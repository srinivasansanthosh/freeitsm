<?php
/**
 * Checklists & SOPs — English strings.
 *
 * ⚠️ FIRST t() USAGE IN THE MODULE. The module was contributed with its screens
 * hardcoded in English (2.0.0, Santhosh Srinivasan). This namespace starts with
 * the help page because that is the page a translator gains most from, and the
 * rest of the module follows. Do not assume `checklists.*` covers the screens —
 * grep before relying on a key existing.
 */

return [
    'editor' => [
        'closure_mode'      => 'Closure gate',
        'closure_mode_desc' => 'How outstanding mandatory steps behave when a ticket moves to a closed status.',
        'closure_warn'      => 'Standard (Warn & record override)',
        'closure_block'     => 'Critical (Block closure until complete)',
    ],
    'nav' => [
        'templates' => 'Templates',
        'settings'  => 'Settings',
        'help'      => 'Help',
    ],

    'help' => [
        'page_title' => 'Checklists - Help',
        'guide'      => 'Checklists guide',
        'hero_title' => 'Checklists',
        'hero_sub'   => 'Write a checklist once, attach it to any ticket, and see at a glance which steps are done. Ideal for tracking Standard Operating Procedures (SOPs) and routine jobs your team does the same way every time — onboarding, offboarding, a firewall change, or a VPN fault.',

        'nav_overview'  => 'Overview',
        'nav_building'  => 'Writing a checklist',
        'nav_steps'     => 'Steps',
        'nav_using'     => 'Using one on a ticket',
        'nav_closing'   => 'Mandatory steps and closing',
        'nav_settings'  => 'Settings',
        'nav_tips'      => 'Tips',

        // 1 ---------------------------------------------------------------
        'overview_heading' => 'What this module is for',
        'overview_intro'   => 'Some jobs are done the same way every time, and the cost of getting one step wrong is high. A new starter with no mailbox. A leaver whose VPN token is still live. This module holds those Standard Operating Procedures and routine jobs as reusable checklist templates, tracking them per ticket so nothing is finished from memory.',

        'card_templates_title' => 'Templates',
        'card_templates_desc'  => 'A checklist written once - a title, a description, and an ordered list of steps. Edit it and every future use picks up the change.',
        'card_attach_title'    => 'Attached per ticket',
        'card_attach_desc'     => 'Attaching a template to a ticket takes a copy of its steps. Ticking them off records who did what and when, on that ticket only.',
        'card_mandatory_title' => 'Mandatory steps',
        'card_mandatory_desc'  => 'Mark the steps that genuinely must happen. FreeITSM can then warn - or refuse - when somebody closes a ticket with one outstanding.',
        'card_suggest_title'   => 'Suggested automatically',
        'card_suggest_desc'    => 'Keywords on a template let FreeITSM offer the right checklist on a ticket before anybody goes looking for it.',

        // 2 ---------------------------------------------------------------
        'building_heading' => 'Writing a checklist',
        'building_intro'   => 'Templates live on the main Checklists screen. <strong>New template</strong> opens the editor on its own screen - it is a writing job, not a dialogue, and it needs the room.',
        'building_step1'   => '<strong>Title</strong> - what the checklist is, as somebody searching would say it. <em>New starter onboarding</em>, not <em>Process 4b</em>.',
        'building_step2'   => '<strong>Category</strong> - groups templates on the list and colours their pill. Categories are yours to define, in <strong>Settings &rarr; Categories</strong>.',
        'building_step3'   => '<strong>Applies to</strong> - whether the checklist is offered on tickets, on tasks, or on both.',
        'building_step4'   => '<strong>Description</strong> - when and why to use this one. This is what somebody reads when deciding between two similar checklists, so it earns its place.',
        'building_step5'   => '<strong>Keywords</strong> - comma separated, and the reason a checklist finds its own ticket. A VPN checklist tagged <code>vpn, remote access, token</code> is offered on a ticket about any of them.',
        'building_note'    => 'Editing a template does not change checklists already attached to tickets. An attached checklist is a copy taken at the moment it was attached, so a ticket half way through a job keeps the steps the analyst started with.',

        // 3 ---------------------------------------------------------------
        'steps_heading' => 'Steps',
        'steps_intro'   => 'A step is one thing the analyst does. Keep them to one action each - a step reading <em>set the account up</em> cannot be ticked off honestly.',
        'steps_order_title' => 'Order',
        'steps_order_desc'  => 'Drag a step by the handle on its left to move it. The numbers renumber themselves. Order is the point of a checklist, so it is worth getting right rather than living with "do step 6 before step 4".',
        'steps_role_title'  => 'Suggested role',
        'steps_role_desc'   => 'Who normally does this step - <em>IT</em>, <em>HR</em>, <em>Facilities</em>. It is guidance printed beside the step, not a permission: anybody can tick any step. Roles are defined in <strong>Settings &rarr; Roles</strong>.',
        'steps_mand_title'  => 'Mandatory',
        'steps_mand_desc'   => 'This step must be done before the ticket is closed. See <em>Mandatory steps and closing</em> below for what FreeITSM does about it.',
        'steps_input_title' => 'Ask for a value',
        'steps_input_desc'  => 'The step asks the analyst to type something when they tick it - an asset tag, a serial number, a reference. Set the prompt so it is obvious what is wanted. The answer is stored against that ticket\'s step and appears beside it.',
        'steps_delete'      => 'Removing a step asks first and tells you which one. Deleting a whole template removes its steps with it.',

        // 4 ---------------------------------------------------------------
        'using_heading' => 'Using one on a ticket',
        'using_intro'   => 'Open a ticket and find the <strong>Checklist</strong> panel. Everything below happens on that ticket alone.',
        'using_step1'   => '<strong>Attach a checklist</strong> - search by name or keyword and press <strong>Attach</strong>. More than one can be attached to the same ticket.',
        'using_step2'   => '<strong>Best match</strong> - if a template\'s keywords match the ticket, FreeITSM offers it at the top without being asked. This is what the keywords are for.',
        'using_step3'   => '<strong>Tick steps off</strong> as you do them. Each tick records who and when; a step set to ask for a value prompts for it as you tick it.',
        'using_step4'   => '<strong>A note is added to the ticket</strong> each time a step is completed or reopened, so the ticket history tells the story on its own - useful months later, and to anybody auditing the work.',
        'using_step5'   => '<strong>Remove</strong> takes a checklist off the ticket. It asks first, because the ticks go with it.',

        // 5 ---------------------------------------------------------------
        'closing_heading' => 'Mandatory steps and closing a ticket',
        'closing_intro'   => 'A mandatory step is only worth marking if something happens when it is skipped. What happens is your choice, in <strong>Tickets &rarr; Settings &rarr; Checklists</strong>.',
        'closing_warn_title'  => 'Warn',
        'closing_warn_desc'   => 'The ticket closes, and an internal note records that it was closed with steps outstanding - which ones, who closed it, and whether they did it from the web interface or the API. The default, and the right setting for most desks: the analyst usually has a reason, and the note means nobody has to remember it.',
        'closing_block_title' => 'Block',
        'closing_block_desc'  => 'The close is refused until the mandatory steps are done, and the message names them. Choose this where the checklist is the point - a regulated process, or a leaver checklist where a missed step leaves access live.',
        'closing_enforced'    => '<strong>Whichever you choose is enforced everywhere a ticket can be closed</strong>, not just on the button in front of you: bulk actions, the REST API and workflow automation all obey it. A rule that only lives in one screen is not a rule, and a gate that can be walked around reports control that is not there.',

        // 6 ---------------------------------------------------------------
        'settings_heading' => 'Settings',
        'settings_intro'   => 'Two screens matter, and they do different jobs.',
        'settings_mod_title'  => 'Checklists &rarr; Settings',
        'settings_mod_desc'   => '<strong>Categories</strong> group templates and colour their pills. <strong>Roles</strong> fill the "suggested role" list on a step. Both show how many steps or templates use an entry before you delete it. <strong>Left panel</strong> is a per-account preference: keep the template sidebar always visible, or let it appear on hover.',
        'settings_tick_title' => 'Tickets &rarr; Settings &rarr; Checklists',
        'settings_tick_desc'  => 'Warn or block on closing a ticket with mandatory steps outstanding. It sits with the ticket settings rather than here because it is a rule about closing tickets, and that is where somebody will look for it.',
        'settings_demo'       => '<strong>System &rarr; Demo data</strong> will seed five realistic checklists - onboarding, offboarding, server decommissioning, VPN troubleshooting and a firewall change - so you can see the module working before writing anything. Removing the demo data removes exactly those and nothing you wrote.',

        // 7 ---------------------------------------------------------------
        'tips_heading' => 'Tips',
        'tip_one_action_title' => 'One action per step',
        'tip_one_action_body'  => 'If a step cannot be answered yes or no, it is two steps. This is the single thing that decides whether a checklist gets used or ignored.',
        'tip_keywords_title'   => 'Spend a minute on keywords',
        'tip_keywords_body'    => 'A checklist nobody can find is a checklist nobody follows. Add the words a requester would use, not the words you would - "cannot get in", not "authentication failure".',
        'tip_mandatory_title'  => 'Be sparing with mandatory',
        'tip_mandatory_body'   => 'Mark everything mandatory and people learn to close through the warning without reading it. Mark the three that matter and the warning still means something.',
        'tip_edit_title'       => 'Improve the template, not the ticket',
        'tip_edit_body'        => 'When somebody finds a missing step mid-job, add it to the template as well. Attached checklists are copies, so the ticket in front of you keeps its steps and every future ticket gets the better procedure.',
    ],
];
