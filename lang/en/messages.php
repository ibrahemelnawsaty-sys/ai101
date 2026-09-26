<?php

/**
 * Internal communication. Mirrors lang/ar/messages.php key for key.
 *
 * @see PRD §9.13, §9.5.3
 */

return [

    'subtitle' => 'Your direct conversations · the cohort group · the announcements channel',
    'threads' => 'Conversations',
    // D-118 — starting a one-to-one conversation.
    'new_conversation' => 'New conversation',
    'edit_hint' => 'You can edit your message within 15 minutes of sending it.',

    'inbox' => [
        'title' => 'System administration',
        'option_hint' => 'Your message reaches every system administrator, and any of them can reply.',
    ],

    'start' => [
        'title' => 'New conversation',
        'subtitle' => 'Choose who to write to, and write your first message.',
        'back' => 'Back to messages',
        'search_label' => 'Search by name or email',
        'search_submit' => 'Search',
        'recipient_legend' => 'To',
        'body_label' => 'Your first message',
        'existing_note' => 'If you already have a conversation together, your message goes there and no second one is opened.',
        'submit' => 'Send and start the conversation',
        'recipient_required' => 'Choose who you want to write to from the list.',
        'body_required' => 'Write your first message before sending.',
        'empty_title' => 'Nobody you can write to right now',
        'empty_body' => 'A conversation starts with whoever your role allows: a trainee with their cohort\'s trainer and coordinator, a trainer with their cohort\'s coordinator, a coordinator with their cohort\'s trainers and trainees and the general supervisor. None of them is available to you now; your existing conversations stay on the messages screen.',
        'no_match_title' => 'No results for this search',
        'no_match_body' => 'Nobody you can write to matches what you typed. Try part of the name or the email.',
    ],

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
    'empty_body' => 'You have no conversations yet. Start one with someone you may write to from the «New conversation» button.',
    'thread_empty_title' => 'No messages in this conversation yet',
    'thread_empty_body' => 'Write the first message — your trainer sees this thread and replies here.',
    'error_title' => 'Your conversations could not be shown',
    'error_body' => 'Something went wrong while fetching them. Try again shortly — your messages are safe and unaffected.',

];
