<?php

/**
 * Support tickets (D-124).
 *
 * The participant is told where their ticket is by role, never by name, and
 * never sees an internal line. The support team sees names and everything.
 *
 * @see D-124 · CONSTITUTION art. 15
 */

return [

    'title' => 'Technical support',
    'subtitle' => 'Your tickets with the support team, and where each one stands.',
    'staff_subtitle' => 'The tickets waiting on you, and every ticket you follow.',
    'new' => 'New ticket',
    'at' => 'With :level',
    'someone' => 'An account that no longer exists',
    'last_activity' => 'Updated :when',

    'count' => [
        'hours' => '{1} one hour|[2,*] :count hours',
        'files' => '{1} one file|[2,*] :count files',
        'waiting' => '{0} No tickets waiting on you|{1} One ticket waiting on you|[2,*] :count tickets waiting on you',
    ],

    'tabs' => [
        'label' => 'Show tickets',
        'waiting' => 'Waiting on me',
        'all' => 'All tickets',
    ],

    'index' => [
        'list_label' => 'Tickets',
        'opener' => 'Participant',
        'cohort' => 'Cohort',
        'waiting_empty_title' => 'Nothing is waiting on you',
        'waiting_empty_body' => 'Every ticket that reaches you appears here, and you are notified of it. Every ticket you follow is under "All tickets".',
        'waiting_empty_action' => 'Show all tickets',
        'all_empty_title' => 'No tickets so far',
        'all_empty_body' => 'Every ticket you follow appears here, and whoever holds it is notified.',
        'error_title' => 'The tickets could not be shown right now',
        'error_body' => 'Something went wrong while loading them, and none were lost. Try again in a moment.',
        'no_cohort' => 'A support ticket reaches your cohort\'s coordinator, and you are not in a cohort now, so no ticket can be opened. If you need help, write to us at :email.',
    ],

    'create' => [
        'title' => 'New support ticket',
        'subtitle' => 'Describe the problem and your ticket reaches your cohort\'s coordinator directly.',
        'intro' => 'Your ticket reaches your cohort\'s coordinator; you are notified of its number on the platform and by e-mail, and you follow it from the Support page.',
        'subject' => 'Subject',
        'subject_hint' => 'A short line describing the problem, such as: I cannot upload my assignment.',
        'category' => 'Category',
        'category_placeholder' => 'Choose a category',
        'body' => 'Description',
        'body_hint' => 'What you tried to do, what happened, and when. The clearer it is, the faster it is solved.',
        'link' => 'A link that shows the problem (optional)',
        'link_hint' => 'A page or a file, starting with https://',
        'files' => 'Pictures or videos (optional)',
        'files_hint' => 'Up to :count, each up to :size MB. Formats: PNG, JPG and WebP for pictures; MP4, MOV and WebM for videos.',
        'submit' => 'Send the ticket',
    ],

    'show' => [
        'meta_label' => 'Ticket details',
        'number' => 'Ticket number',
        'status' => 'Status',
        'category' => 'Category',
        'where' => 'Where it is now',
        'opener' => 'Opened by',
        'cohort' => 'Cohort',
        'holder' => 'Coordinator holding it',
        'opened' => 'Opened',
        'timeline' => 'The ticket\'s history',
        'internal' => 'Internal',
        'internal_hint' => 'Not shown to the participant',
        'attachments' => 'Attachments',
        'open_file' => 'Open :name',
        'video_label' => 'Video: :name',
        'video_failed' => 'This clip cannot play in your browser. Open it from the link below to download it.',
        'new_tab' => 'opens in a new window',
        'link' => 'Attached link',
        'resolved_note' => 'Your ticket was resolved. If the problem is still there, reply before :when to send it back to the coordinator; otherwise it closes automatically.',
        'resolved_note_staff' => 'Resolved; it closes automatically :when unless the participant replies.',
        'closed_note' => 'This ticket was closed :when. If you need more help, open a new ticket.',
        'closed_note_staff' => 'This ticket was closed :when.',
        'back' => 'Back to the tickets',
    ],

    'entry' => [
        'opened' => 'Ticket opened',
        'reply' => 'Your reply',
        'message' => 'A message from the coordinator',
        'note' => 'A note from :level',
        'escalated' => 'Moved to :to',
        'returned' => 'Returned to :to',
        'resolved' => 'Resolved',
        'reopened' => 'Reopened by your reply',
        'closed' => 'Closed at your request',
        'auto_closed' => 'Closed automatically :hours after it was resolved',
    ],

    'staff_entry' => [
        'opened' => 'Ticket opened · :name',
        'opened_safety_net' => 'Ticket opened and sent straight to the general supervisor, since the cohort has no primary coordinator · :name',
        'reply' => 'Participant reply · :name',
        'message' => 'Message to the participant · :name',
        'note' => 'Note shown to the participant · :name',
        'note_internal' => 'Internal note · :name',
        'escalated' => 'Moved to :to · :name',
        'escalated_system' => 'Moved to :to automatically: its coordinator is no longer available and the cohort has no primary coordinator',
        'returned' => 'Returned to :to · :name',
        'returned_to' => 'Returned to coordinator :target · :name',
        'assigned' => 'Handed to coordinator :target · :name',
        'assigned_system' => 'Moved automatically to the primary coordinator :target: its previous coordinator is no longer available',
        'resolved' => 'Resolved · :name',
        'reopened' => 'Reopened by the participant\'s reply · :name',
        'closed' => 'Closed by its owner · :name',
        'auto_closed' => 'Closed automatically :hours after it was resolved',
    ],

    'reply' => [
        'title' => 'Reply to the ticket',
        'body' => 'Your reply',
        'submit' => 'Send the reply',
        'reopens' => 'Replying now reopens the ticket and sends it back to the coordinator.',
    ],

    'close' => [
        'title' => 'Problem solved?',
        'hint' => 'Close the ticket if you no longer need it. A closed ticket takes no more replies, and you can open a new one at any time.',
        'submit' => 'Close the ticket',
    ],

    'actions' => [
        'title' => 'Ticket actions',
        'compose' => 'Reply or note',
        'compose_internal' => 'Internal note',
        'internal' => 'Internal note — the participant does not see it',
        'internal_only' => 'Your note is internal and the participant does not see it, since the ticket is not with you now.',
        'internal_level' => 'Your note here is for the support team alone and the participant does not see it: the cohort\'s coordinator writes to them once the ticket returns.',
        'message_hint' => 'Unless you make it internal, it appears in the participant\'s history of the ticket.',
        'submit_message' => 'Send to the participant',
        'submit_internal' => 'Add an internal note',
        'resolve' => 'Resolved',
        'resolve_body' => 'A summary of the solution for the participant (optional)',
        'resolve_hint' => 'The participant is notified on the platform and by e-mail, and the ticket closes automatically after :hours unless they reply.',
        'escalate' => 'Move it to :to',
        'escalate_note' => 'A note for whoever receives it (internal, optional)',
        'escalate_hint' => 'The participant is told it is now with :to.',
        'return' => 'Return it to :to',
        'return_coordinator' => 'Coordinator it returns to',
        'return_note' => 'What was done (internal note, optional)',
        'return_hint' => 'The participant is told it went back to :to.',
        'primary_mark' => ':name — primary coordinator',
        'assign' => 'Hand it to another coordinator of the cohort',
        'assign_coordinator' => 'Choose a coordinator',
        'assign_note' => 'A note for the receiving coordinator (internal, optional)',
        'assign_hint' => 'The participant still sees "with the coordinator", and is not notified.',
        'assign_submit' => 'Hand it over',
        'no_coordinator' => 'This cohort has no coordinator to return the ticket to. Assign one from the cohorts screen first.',
        'follow_only' => 'You follow this ticket, and act on it once it reaches you.',
    ],

    'flash' => [
        'opened' => 'Your ticket arrived as :number. Replies appear here, and you get an e-mail when it moves, is resolved or is closed.',
        'replied' => 'Your reply was sent.',
        'reopened' => 'Your reply was sent, and the ticket went back to the coordinator.',
        'closed' => 'The ticket is closed.',
        'noted' => 'Your note was added.',
        'messaged' => 'Your message reached the participant.',
        'resolved' => 'The ticket is marked resolved and the participant was told.',
        'escalated' => 'The ticket moved to :to.',
        'returned' => 'The ticket went back to :to.',
        'assigned' => 'The ticket was handed to coordinator :name.',
    ],

    'errors' => [
        'closed' => 'This ticket is closed, so nothing more can be added to it. If you need more help, open a new ticket.',
        'moved_on' => 'The ticket changed before your request arrived; someone else may have acted on it. Refresh the page to see where it stands.',
        'not_a_coordinator' => 'The coordinator you chose is not an available coordinator of this cohort. Choose another from the list.',
        'no_coordinator' => 'This ticket\'s cohort has no coordinator to return it to. Assign one from the cohorts screen, then return it.',
        'subject' => 'Write a short subject of at most :max characters.',
        'category' => 'Choose the ticket\'s category from the list.',
        'body' => 'Write the text before sending, in at most :max characters.',
        'link' => 'That link is not valid. Copy it in full so it starts with https://',
        'files_count' => 'Choose at most :count, then send again.',
        'file_type' => 'One of the files is not an accepted picture or video. Attach PNG, JPG, WebP, MP4, MOV or WebM.',
        'file_size' => 'One of the files is larger than :size MB. Choose a shorter clip or a smaller picture and send again.',
        'coordinator' => 'Choose a coordinator from the list.',
        'note' => 'The note is longer than :max characters. Shorten it and send again.',
    ],

    'notices' => [
        'opened' => [
            'title' => 'Your ticket :number arrived',
            'body' => 'We received your ticket ":subject"; it is now with :level. Replies will reach you here.',
        ],
        'moved' => [
            'title' => 'Your ticket :number is now with :level',
            'body' => 'Your ticket ":subject" moved to :level to be followed up.',
        ],
        'message' => [
            'title' => 'A new message on your ticket :number',
            'body' => 'The coordinator wrote to you about ":subject". Open the ticket to read it.',
        ],
        'resolved' => [
            'title' => 'Your ticket :number was resolved',
            'body' => 'If ":subject" is still a problem, reply to the ticket within :hours to send it back to the coordinator; otherwise it closes automatically.',
        ],
        'closed' => [
            'title' => 'Your ticket :number was closed',
            'body' => 'Your ticket ":subject" was closed. If you need more help, open a new ticket.',
        ],
        'arrived' => [
            'title' => 'A support ticket is waiting on you: :number',
            'body' => 'The ticket ":subject" reached you. Open it to follow it up.',
        ],
        'replied' => [
            'title' => 'A new reply on ticket :number',
            'body' => 'The participant replied on ":subject".',
        ],
        'reopened' => [
            'title' => 'Ticket :number was reopened',
            'body' => 'The participant replied after "Resolved", so ":subject" is back with you in progress.',
        ],
    ],

];
