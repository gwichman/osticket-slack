<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');
require_once('config.php');

class SlackPlugin extends Plugin {

    /** Turn on verbose debug logging to /tmp/slack_dbg.log */
    const DEBUG = false;
    
    var $config_class = "SlackPluginConfig";

    static $pluginInstance = null;

    private function getPluginInstance(?int $id) {
        if($id && ($i = $this->getInstance($id)))
            return $i;

        return $this->getInstances()->first();
    }

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    function bootstrap() {
        // get plugin instances
        self::$pluginInstance = self::getPluginInstance(null);

        $updateTypes = $this->getConfig(self::$pluginInstance)->get('slack-update-types');
        
        // Listen for osTicket to tell us it's made a new ticket or updated
        // an existing ticket:
        if($updateTypes == 'both' || $updateTypes == 'newOnly' || empty($updateTypes)) {
            Signal::connect('ticket.created', array($this, 'onTicketCreated'));
        }
        
        if($updateTypes == 'both' || $updateTypes == 'updatesOnly' || empty($updateTypes)) {
            Signal::connect('threadentry.created', array($this, 'onTicketUpdated'));
            Signal::connect('ticket.updated',      array($this, 'onTicketUpdated'));
        }
    }

    /**
     * What to do with a new Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @return type
     */
    function onTicketCreated(Ticket $ticket) {
        if (self::DEBUG) {
            file_put_contents(
                '/tmp/slack_dbg.log',
                date('[Y-m-d H:i:s] ') . "[SLACK-DBG] onTicketCreated  T#{$ticket->getId()}\n",
                FILE_APPEND
            );
        }

        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }
        
        // if slack-update-types is "updatesOnly", then don't send this!
        if($this->getConfig(self::$pluginInstance)->get('slack-update-types') == 'updatesOnly') {return;}

        // Convert any HTML in the message into text
        $plaintext = Format::html2text($ticket->getMessages()[0]->getBody()->getClean());

        // Format the messages we'll send.
        $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s'
                , __("New Ticket")
                , $cfg->getUrl()
                , $ticket->getId()
                , $ticket->getNumber()
                , __("created"));
        $this->sendToSlack($ticket, $heading, $plaintext);
    }

    /**
     * What to do with an Updated Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param ThreadEntry $entry
     * @return type
     */
    function onTicketUpdated($obj) {
        global $cfg;

        if (self::DEBUG) {
            file_put_contents(
                '/tmp/slack_dbg.log',
                date('[Y-m-d H:i:s] ') . "[SLACK-DBG] onTicketUpdated obj=" .
                    (is_object($obj) ? get_class($obj) : gettype($obj)) . "\n",
                FILE_APPEND
            );
        }

        /* --- ThreadEntry (a reply/note) --- */
        if ($obj instanceof ThreadEntry) {
            // Original logic unchanged
            $ticket = $this->getTicket($obj);
            if (!$ticket instanceof Ticket) return;
    
            // Skip first message (it’s actually ticket.created)
            $first = $ticket->getMessages()[0];
            if ($obj->getId() == $first->getId()) return;
    
            $plaintext = Format::html2text($obj->getBody()->getClean());
            $heading   = sprintf('%s #%s %s',
                __("Ticket"), $ticket->getNumber(), __("updated"));
    
            $this->sendToSlack($ticket, $heading, $plaintext, 'warning');
            return;
        }
    
        /* --- Field-only update (our priority filter triggers this) --- */
        if ($obj instanceof Ticket) {
            $ticket  = $obj;
            $heading = sprintf('%s #%s – %s *%s*',
                __("Ticket"), $ticket->getNumber(),
                __("priority changed to"), $ticket->getPriority()->priority);
    
            $this->sendToSlack($ticket, $heading, '', 'warning');
        }
    }

    /**
     * A helper function that sends messages to slack endpoints. 
     * 
     * @global osTicket $ost
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @param string $heading
     * @param string $body
     * @param string $colour
     * @throws \Exception
     */
    function sendToSlack(Ticket $ticket, $heading, $body, $colour = 'good') {

        global $ost, $cfg;

        $baseUrl   = rtrim($cfg->getUrl(), '/');          // ensure no trailing slash
        $ticketUrl = $baseUrl . '/scp/tickets.php?id=' . $ticket->getId();
    
        /* Debug probe (works in CLI and FPM) */
        if (self::DEBUG) {
            file_put_contents(
                '/tmp/slack_dbg.log',
                date('[Y-m-d H:i:s] ') . "[SLACK-DBG] sendToSlack begin T#{$ticket->getId()}\n",
                FILE_APPEND
            );
        }
    
        /* Only cfg is mandatory — $ost may be null in CLI */
        if (!$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }
        $url = $this->getConfig(self::$pluginInstance)->get('slack-webhook-url');
        if (!$url) {
            if ($ost instanceof osTicket) {
                $ost->logError('Slack Plugin not configured', 'You need to read the Readme and configure a webhook URL before using this.');
            }
        }

        /* Fancy colours based on urgency ----------------------------- */
        $urgency = $ticket->getPriority() ? $ticket->getPriority()->priority_urgency : 1;
        $urg = $urgency - 1;             // 0-based for P0/P1 labels
        $colour_map = ['#e01e5a', '#e38200', '#2eb67d', '#439fe0'];
        $colour = $colour_map[$urg] ?? '#2eb67d';

        /* --- Urgency-to-emoji map (place it here) ------------------- */
        $urgencyEmoji = [
            1 => ':rotating_light:',       // Emergency / P0
            2 => ':large_orange_diamond:', // High / P1
            3 => ':white_circle:',         // Normal / P2
            4 => ':white_circle:',         // Low    / P3
        ];
        
        // --- Priority whitelist filter ------------------------------------
        $allowed = $this->getConfig(self::$pluginInstance)->get('priority-whitelist');
        if (is_array($allowed) && $allowed) {
            // array_keys() gives us [ '4', '3' ] — the IDs
            if (!in_array($ticket->getPriorityId(), array_keys($allowed))) {
                return;   // skip — priority not whitelisted
            }
        }
       
        // Check the subject, see if we want to filter it.
        $regex_subject_ignore = $this->getConfig(self::$pluginInstance)->get('slack-regex-subject-ignore');
        // Filter on subject, and validate regex:
        if ($regex_subject_ignore && preg_match("/$regex_subject_ignore/i", $ticket->getSubject())) {
            if ($ost instanceof osTicket) {
                $ost->logDebug('Ignored Message', 'Slack notification was not sent because the subject (' . $ticket->getSubject() . ') matched regex (' . htmlspecialchars($regex_subject_ignore) . ').');
            }
        }

        $heading = $this->format_text($heading);

        // Pull template from config, and use that.
        $template    = $this->getConfig(self::$pluginInstance)->get('message-template');
        $custom_vars = [
            'slack_safe_message' => $this->format_text($body),
        ];
        // priority-urgency minus 1 so Emergency = P0
        $custom_vars['ticket.priority_urgency_minus1'] = $urgency - 1;
        $formatted_message = $ticket->replaceVars($template, $custom_vars);

        // Build modern Block Kit payload --------------------------
        $blocks = [
          [
            'type' => 'section',
            'text' => [
              'type' => 'mrkdwn',
                // 🔴🟠🟢 show coloured emoji per urgency
                /* --- Header line --------------------------------------------- */
                'text' => sprintf(
                    '%s *%s P%d* – <%s|%s>',
                    // Emoji per priority:
                    $urgencyEmoji[$urgency] ?? '',
                    // Priority label:
                    ucfirst($ticket->getPriority()->priority),
                    // P-number (0-based):
                    $urgency - 1,
                    // Link:
                    $ticketUrl,
                    $ticket->getSubject()
                )
            ]
          ],
          [
            'type' => 'context',
            'elements' => [[
              'type' => 'mrkdwn',
              'text' => sprintf('From *%s* · %s',
                $ticket->getName()->getFull(),
                $ticket->getDept())
            ]]
          ],
          [
            'type' => 'section',
            'text' => [
              'type' => 'mrkdwn',
              'text' => '```'.mb_substr($body,0,500).'```'
            ]
          ],
          [
            'type' => 'actions',
            'elements' => [[
              'type' => 'button',
              'text' => ['type'=>'plain_text','text'=>'View Ticket'],
              'url'  => $ticketUrl
            ]]
          ]
        ];
        
        // // Build the payload with the formatted data:
        $payload = [
          'blocks'      => $blocks,
          'attachments' => [[
              'color'    => $colour,
              'fallback' => ' '   // <-- add this line
          ]],
        ];

        // Add a field for tasks if there are open ones
        if ($ticket->getNumOpenTasks()) {
            $payload['attachments'][0]['fields'][] = [
                'title' => __('Open Tasks'),
                'value' => $ticket->getNumOpenTasks(),
                'short' => TRUE,
            ];
        }

        // Change the colour to Fuschia if ticket is overdue
        if ($ticket->isOverdue()) {
            $payload['attachments'][0]['color'] = '#ff00ff';
        }

        // Format the payload:
        $data_string = utf8_encode(json_encode($payload));

        try {
            // Setup curl
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string))
            );

            // Actually send the payload to slack:
            if (self::DEBUG) {
                file_put_contents(
                    '/tmp/slack_dbg.log',
                    date('[Y-m-d H:i:s] ') . "[SLACK-DBG] curl_exec finished\n",
                    FILE_APPEND
                );
            }
            if (curl_exec($ch) === false) {
                throw new \Exception($url . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (self::DEBUG) {
                    file_put_contents(
                        '/tmp/slack_dbg.log',
                        date('[Y-m-d H:i:s] ') . "[SLACK-DBG] HTTP {$statusCode}\n",
                        FILE_APPEND
                    );
                }
                if ($statusCode != '200') {
                    throw new \Exception(
                    'Error sending to: ' . $url
                    . ' Http code: ' . $statusCode
                    . ' curl-error: ' . curl_errno($ch));
                }
            }
        } catch (\Exception $e) {
            if ($ost instanceof osTicket) {
                $ost->logError('Slack posting issue!', $e->getMessage(), true);
            }
            error_log('Error posting to Slack. ' . $e->getMessage());
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Fetches a ticket from a ThreadEntry
     *
     * @param ThreadEntry $entry        	
     * @return Ticket
     */
    function getTicket(ThreadEntry $entry) {
        $ticket_id = Thread::objects()->filter([
                    'id' => $entry->getThreadId()
                ])->values_flat('object_id')->first() [0];

        // Force lookup rather than use cached data..
        // This ensures we get the full ticket, with all
        // thread entries etc.. 
        return Ticket::lookup(array(
                    'ticket_id' => $ticket_id
        ));
    }

    /**
     * Formats text according to the 
     * formatting rules:https://api.slack.com/docs/message-formatting
     * 
     * @param string $text
     * @return string
     */
    function format_text($text) {
        $formatter      = [
            '<' => '&lt;',
            '>' => '&gt;',
            '&' => '&amp;'
        ];
        $formatted_text = str_replace(array_keys($formatter), array_values($formatter), $text);
        // put the <>'s control characters back in
        $moreformatter  = [
            'CONTROLSTART' => '<',
            'CONTROLEND'   => '>'
        ];
        // Replace the CONTROL characters, and limit text length to 500 characters.
        return mb_substr(str_replace(array_keys($moreformatter), array_values($moreformatter), $formatted_text), 0, 500);
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param boole $img True to return a complete IMG tag False for just the URL
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @return String containing either just a URL or a complete image tag
     * @source https://gravatar.com/site/implement/images/php/
     */
    function get_gravatar($email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array()) {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r";
        if ($img) {
            $url = '<img src="' . $url . '"';
            foreach ($atts as $key => $val)
                $url .= ' ' . $key . '="' . $val . '"';
            $url .= ' />';
        }
        return $url;
    }

}
