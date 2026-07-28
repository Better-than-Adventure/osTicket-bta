<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');
require_once('config.php');

class DiscordPlugin extends Plugin {

    var $config_class = "DiscordPluginConfig";
    var $instanceConfig = null;

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    function bootstrap() {
        $pluginInstance = new DiscordPlugin();
        $pluginInstance->instanceConfig = $this->getConfig();

        // Listen for osTicket to tell us it's made a new ticket or updated
        // an existing ticket:

        Signal::connect('ticket.created', array($pluginInstance, 'onTicketCreated'));
        Signal::connect('threadentry.created', array($pluginInstance, 'onTicketUpdated'));
    }

    function onTicketCreated(Ticket $ticket) {
        global $cfg;

        if(!$cfg instanceof OsTicketConfig){
            error_log("Webhook Plugin calls too early.");
        }

        $help_topic = $this->getHelpTopic();
        if ($ticket->getHelpTopic() != $help_topic->getFullName()) {
            // Filters out tickets not pertaining to the instance's set help topic.
            error_log("no.");
            return;
        }

        $type = 'Ticket created: ';

        $this->sendToWebhook($ticket, $type);
    }

    function onTicketUpdated(ThreadEntry $entry) {
        $type = 'Ticket Updated: ';
        if (!$entry instanceof MessageThreadEntry) {
            // this was a reply or a system entry.. not a message from a user
            return;
        }

        // Need to fetch the ticket from the ThreadEntry
        $ticket = $this->getTicket($entry);
        if (!$ticket instanceof Ticket) {
            // Admin created ticket's won't work here.
            return;
        }

        $help_topic = $this->getHelpTopic();
        if ($ticket->getHelpTopic() != $help_topic->getFullName()) {
            // Filters out tickets not pertaining to the instance's set help topic.
            error_log("no.");
            return;
        }

        // Check to make sure this entry isn't the first (ie: a New ticket)
        $first_entry = $ticket->getMessages()[0];
        if ($entry->getId() == $first_entry->getId()) {
            return;
        }

        $this->sendToWebhook($ticket, $type);
    }

    /**
     * A helper function that sends messages to teams endpoints.
     *
     * @global osTicket $ost
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @param string $heading
     * @param string $body
     * @param string $colour
     * @throws \Exception
     */
    function sendToWebhook(Ticket $ticket, $type) {
        global $ost,$cfg;

        if(!$cfg instanceof OsTicketConfig){
            error_log("Webhook Plugin calls too early.");
            return;
        }

        $url = $this->instanceConfig->get('discord-webhook-url');

        if (!$url) {
            $ost->logError('Discord Plugin not configured', 'You need to read the Readme and configure a webhook URL before using this.');
        }

        // Build the payload with the formatted data:
        $payload = $this->createJsonMessage($ticket, $type);

        try {
            // Setup curl
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, "false");
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload))
            );

            // Actually send the payload to Teams:
            if (curl_exec($ch) === false) {
                throw new \Exception($url . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($statusCode != '200' && $statusCode != '204') {
                    throw new \Exception(
                        'Error sending to: ' . $url
                        . ' Http code: ' . $statusCode
                        . ' curl-error: ' . curl_errno($ch)
                        . ' payload: ' . $payload);
                }
            }
        } catch (\Exception $e) {
            $ost->logError('Webhook posting issue!', $e->getMessage(), true);
            error_log('Error posting to Webhook. ' . $e->getMessage());
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

    function getHelpTopic(): Topic {
        $help_topic_id = $this->instanceConfig->get('discord-topic-id');
        // Fetch help topic assigned to instance via the config.
        return Topic::lookup(array(
            'topic_id' => $help_topic_id
        ));
    }

    /**
     * Formats text according to the
     * formatting rules:https://docs.microsoft.com/en-us/outlook/actionable-messages/adaptive-card
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
        return substr(str_replace(array_keys($moreformatter), array_values($moreformatter), $formatted_text), 0, 500);
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

    /**
     * @param $ticket
     * @param string $color
     * @param null $type
     * @return false|string
     */
    private function createJsonMessage($ticket, $type = null)
    {
        global $cfg;
        $timestamp = date("c", strtotime("now"));

        $message = $ticket->getLastMessage()->getBody();

        $embed = [
             // Embed Title
             "title" =>  $this->format_text($type . $ticket->getSubject()),

             // Embed Type
             "type" => "rich",

             // URL of title link
             "url" => $cfg->getUrl() . '/scp/tickets.php?id=' . $ticket->getId(),

             // Timestamp of embed must be formatted as ISO8601
             "timestamp" => $timestamp,

             // Embed left border color in HEX
             "color" => hexdec( "5aa938" ),

             // Additional Fields array
             "fields" => [
                 [
                     "name" => "Ticket Type",
                     "value" => $ticket->getHelpTopic(),
                     "inline" => true
                 ],
                 [
                     "name" => "Status",
                     "value" => $ticket->getStatus()->name,
                     "inline" => true
                 ],
                 [
                     "name" => "Message",
                     "value" => strip_tags($ticket->getLastMessage()->getBody()->toHtml()),
                     "inline" => false
                 ]
                 // Etc..
             ],

             // Footer
             "footer" => [
                 "text" => $cfg->getUrl(),
                 "icon_url" => $this->get_gravatar($ticket->getEmail()),
             ],

             // Author
             "author" => [
                 "name" => "BTA! Support",
                 "url" => $this->get_gravatar($ticket->getEmail()),
             ],
        ];

        //Prepare message array to convert to json
        $message = [
            // Username
            "username" => "BTA! Support",

            // Avatar URL.
            // Uncoment to replace image set in webhook
            "avatar_url" => $this->get_gravatar($ticket->getEmail()),

            // Text-to-speech
            "tts" => false,

            // File upload
            // "file" => "",

            // Embeds Array
            "embeds" => [$embed]
        ];

        return json_encode($message, JSON_UNESCAPED_SLASHES);

    }

}
