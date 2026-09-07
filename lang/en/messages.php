<?php

/**
 * Internal communication. Mirrors lang/ar/messages.php key for key.
 *
 * @see PRD §9.13, §9.5.3
 */

return [

    'subtitle' => 'Your trainer chat · the cohort group · the announcements channel',
    'threads' => 'Conversations',

    'compose_label' => 'Message text',
    'compose_placeholder' => 'Write your message…',
    'send' => 'Send',
    'attach' => 'Attach a file',
    'locked' => 'Posting is paused',
    'sent' => 'Sent',
    'read' => 'Read',
    'unread_count' => '{0} no unread messages|{1} one unread message|[2,*] :count unread messages',
    'edit_window_hint' => 'You can edit or delete your message within 15 minutes of sending it.',
    'report' => 'Report this message',
    'mute_thread' => 'Mute this conversation',
    'edited' => 'Your edit was saved.',
    'reported' => 'Your report reached the team. Thank you.',

    'announcements' => [
        'channel' => 'Announcements channel',
        'latest' => 'Latest announcements',
        'empty_title' => 'No announcements yet',
        'empty_body' => 'Announcements from the programme team appear here as soon as they are posted, and you are notified about each one.',
    ],

    'errors' => [
        'empty' => 'Write something or attach a file before sending.',
        'report_reason_short' => 'Describe why you are reporting this in a clear sentence, so the team can review it.',
        'thread_locked' => 'Your trainer has paused posting in this conversation.',
        'announcement_readonly' => 'The announcements channel is read-only. Use your trainer chat for questions.',
        'edit_window_closed' => 'The 15-minute window for editing this message has passed.',
    ],

    'empty_title' => 'Start a conversation',
    'empty_body' => 'Start a conversation with your trainer or with your peers.',
    'thread_empty_title' => 'No messages in this conversation yet',
    'thread_empty_body' => 'Write the first message — your trainer sees this thread and replies here.',
    'error_title' => 'Your conversations could not be shown',
    'error_body' => 'Something went wrong while fetching them. Try again shortly — your messages are safe and unaffected.',

];
