<?php

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.ticket.php';

class SlackPluginConfig extends PluginConfig {

    // Provide compatibility function for versions of osTicket prior to
    // translation support (v1.9.4)
    function translate() {
        if (!method_exists('Plugin', 'translate')) {
            return array(
                function ($x) {
                    return $x;
                },
                function ($x, $y, $n) {
                    return $n != 1 ? $y : $x;
                }
            );
        }
        return Plugin::translate('slack');
    }

    function pre_save(&$config, &$errors) {
        if ($config['slack-regex-subject-ignore'] && false === @preg_match("/{$config['slack-regex-subject-ignore']}/i", null)) {
            $errors['err'] = 'Your regex was invalid, try something like "spam", it will become: "/spam/i" when we use it.';
            return FALSE;
        }
        return TRUE;
    }

    /**
     * Build [priority_id => "Emergency (P0)", …] for the ChoiceField
     */
    private function getPriorityChoices() {
        // Make sure the file is in; it defines class Priority
        require_once INCLUDE_DIR . 'class.priority.php';

        // Use whichever class name is actually declared
        $model = class_exists('Priority') ? 'Priority' : 'TicketPriority';

        $out = [];
        foreach ($model::objects()->order_by('priority_urgency')->all() as $p) {
            $out[$p->getId()] = sprintf('%s (P%d)',
                ucfirst($p->priority), $p->priority_urgency-1);
        }
        return $out;
    }
    
    function getOptions() {
        list ($__, $_N) = self::translate();

        return array(
            'slack'                      => new SectionBreakField(array(
                'label' => $__('Slack notifier'),
                'hint'  => $__('Readme first: https://github.com/clonemeagain/osticket-slack')
                    )),
            'slack-webhook-url'          => new TextboxField(array(
                'label'         => $__('Webhook URL'),
                'configuration' => array(
                    'size'   => 100,
                    'length' => 200
                ),
                    )),
            'slack-regex-subject-ignore' => new TextboxField([
                'label'         => $__('Ignore when subject equals regex'),
                'hint'          => $__('Auto delimited, always case-insensitive'),
                'configuration' => [
                    'size'   => 30,
                    'length' => 200
                ],
                    ]),
            'slack-update-types' => new ChoiceField([
                'label'         => $__('Update Types'),
                'hint'          => $__('What types of updates should be sent via Slack?'),
                'choices' => array('both' => 'New & Updated Tickets', 'updatesOnly' => 'Only Ticket Updates', 'newOnly' => 'Only New Tickets'),
                'default' => 'both',
                'configuration' => [
                    'size'   => 30,
                    'length' => 200
                ],
                    ]),
            'priority-whitelist' => new ChoiceField([
                'label'         => $__('Notify only for these priorities'),
                'hint'          => $__('Leave blank to notify on *all* priorities'),
                'choices'       => $this->getPriorityChoices(),
                'configuration' => ['multiselect' => true],
                    ]),
            'message-template'           => new TextareaField([
                'label'         => $__('Message Template'),
                'hint'          => $__('The main text part of the Slack message, uses Ticket Variables, for what the user typed, use variable: %{slack_safe_message}'),
                // "<%{url}/scp/tickets.php?id=%{ticket.id}|%{ticket.subject}>\n" // Already included as Title
                'default'       => "%{ticket.name.full} (%{ticket.email}) in *%{ticket.dept}* _%{ticket.topic}_\n\n```%{slack_safe_message}```",
                'configuration' => [
                    'html' => FALSE,
                ]
                    ])
        );
    }

}
