<?php

use Moxl\Xec\Action\Message\Publish;
use Moxl\Xec\Action\Message\Reactions;

use Moxl\Xec\Action\Muc\GetConfig;
use Moxl\Xec\Action\Muc\SetConfig;

use App\Message;
use App\Reaction;

use Moxl\Xec\Action\BOB\Request;
use Moxl\Xec\Action\Disco\Request as DiscoRequest;

use Respect\Validation\Validator;

use Illuminate\Database\Capsule\Manager as DB;

use Movim\Picture;
use Movim\ChatStates;
use Movim\ChatOwnState;

class Chat extends \Movim\Widget\Base
{
    private $_pagination = 50;
    private $_wrapper = [];
    private $_messageTypes = ['chat', 'headline', 'invitation', 'jingle_start', 'jingle_end'];
    private $_mucPresences = [];

    public function load()
    {
        $this->addjs('chat.js');
        $this->addcss('chat.css');
        $this->registerEvent('carbons', 'onMessage');
        $this->registerEvent('message', 'onMessage');
        $this->registerEvent('presence', 'onPresence', 'chat');
        $this->registerEvent('retracted', 'onRetracted');
        $this->registerEvent('expired', 'onExpired');
        $this->registerEvent('receiptack', 'onMessageReceipt');
        $this->registerEvent('displayed', 'onMessage', 'chat');
        $this->registerEvent('mam_get_handle', 'onMAMRetrieved');
        $this->registerEvent('mam_get_handle_muc', 'onMAMMucRetrieved', 'chat');
        $this->registerEvent('chatstate', 'onChatState', 'chat');
        //$this->registerEvent('subject', 'onConferenceSubject', 'chat'); Spam the UI during authentication
        $this->registerEvent('muc_setsubject_handle', 'onConferenceSubject', 'chat');
        $this->registerEvent('muc_getconfig_handle', 'onRoomConfig', 'chat');
        $this->registerEvent('muc_setconfig_handle', 'onRoomConfigSaved', 'chat');
        $this->registerEvent('muc_setconfig_error', 'onRoomConfigError', 'chat');
        $this->registerEvent('presence_muc_handle', 'onMucConnected', 'chat');
        $this->registerEvent('message_publish_error', 'onPublishError', 'chat');

        $this->registerEvent('bob_request_handle', 'onSticker');
        $this->registerEvent('notification_counter_clear', 'onNotificationCounterClear');
    }

    public function onPresence($packet)
    {
        if ($packet->content && $jid = $packet->content->jid) {
            $arr = explode('|', (new Notification)->getCurrent());

            if (isset($arr[1]) && $jid == $arr[1] && !$packet->content->muc) {
                $this->ajaxGetHeader($jid);
            }
        }
    }

    public function onMessageReceipt($packet)
    {
        $this->onMessage($packet, false, true);
    }

    public function onRetracted($packet)
    {
        $this->onMessage($packet, false, true);
    }

    public function onExpired(Message $message)
    {
        $this->rpc('Chat.removeMessage', $message);
    }

    public function onNotificationCounterClear($params)
    {
        list($page, $jid) = array_pad($params, 3, null);

        if ($page === 'chat') {
            // Check if the jid is a connected chatroom
            $presence = $this->user->session->presences()
                ->where('jid', $jid)
                ->where('mucjid', $this->user->id)
                ->first();

            $this->prepareMessages($jid, ($presence), true);
        }
    }

    public function onPublishError($packet)
    {
        Toast::send(
            $packet->content ??
            $this->__('chat.publish_error')
        );
    }

    public function onMessage($packet, $history = false, $receipt = false)
    {
        $message = $packet->content;
        $from = null;
        $chatStates = ChatStates::getInstance();

        $rawbody = $message->body;

        if ($message->isEmpty()) {
            return;
        }

        if ($message->file) {
            $rawbody = (typeIsPicture($message->file['type']))
                ? '🖼️ ' . $this->__('chats.picture')
                : '📄 ' . $this->__('avatar.file');
        }

        if ($message->user_id == $message->jidto
        && !$history
        && $message->seen == false
        && $message->jidfrom != $message->jidto) {
            $from = $message->jidfrom;
            $contact = App\Contact::firstOrNew(['id' => $from]);

            $conference = $message->type == 'groupchat'
                ? $this->user->session
                    ->conferences()->where('conference', $from)
                    ->first()
                : null;

            if ($contact != null
            && !$message->encrypted
            && $message->type != 'groupchat'
            && !$message->oldid) {
                $roster = $this->user->session->contacts()->where('jid', $from)->first();
                $chatStates->clearState($from);

                Notification::rpcCall('Notification.incomingMessage');
                Notification::append(
                    'chat|'.$from,
                    $roster ? $roster->truename : $contact->truename,
                    $rawbody,
                    $contact->getPhoto(),
                    4,
                    $this->route('chat', $contact->jid)
                );
            }
            // If it's a groupchat message
            elseif ($message->type == 'groupchat'
                && $conference
                && (($conference->notify == 1 && $message->quoted) // When quoted
                  || $conference->notify == 2) // Always
                && !$receipt) {
                Notification::rpcCall('Notification.incomingMessage');
                Notification::append(
                    'chat|'.$from,
                    ($conference != null && $conference->name)
                        ? $conference->name
                        : $from,
                    $message->resource.': '.$rawbody,
                    $conference->getPhoto(),
                    4,
                    $this->route('chat', [$contact->jid, 'room'])
                );
            } elseif ($message->type == 'groupchat') {
                if ($conference && $conference->notify == 0) {
                    $message->seen = true;
                    $message->save();
                }

                $chatStates->clearState($from, $message->resource);
            }

            $this->onChatState($chatStates->getState($from));
        }

        if (!$message->encrypted) {
            $this->rpc('Chat.appendMessagesWrapper', $this->prepareMessage($message, $from));
        }

        $this->event('chat_counter', $this->user->unreads());
    }

    public function onSticker($packet)
    {
        list($to, $cid) = array_values($packet->content);
        $this->ajaxGet($to);
    }

    public function onChatState(array $array, $first = true)
    {
        if (isset($array[1])) {
            $this->setState(
                $array[0],
                is_array($array[1]) && !empty($array[1])
                    ? $this->prepareComposeList(array_keys($array[1]))
                    : $this->__('message.composing'),
                $first
            );
        } else {
            $this->setState($array[0], '', $first);
        }
    }

    public function onConferenceSubject($packet)
    {
        $this->ajaxGetRoom($packet->content->jidfrom, false, true);
    }

    public function onMAMRetrieved()
    {
        Toast::send($this->__('chat.mam_retrieval'));
    }

    public function onMAMMucRetrieved($packet)
    {
        $this->ajaxGetRoom($packet->content, true, true);
    }

    public function onMucConnected($packet)
    {
        list($content, $notify) = $packet->content;

        if ($notify) {
            $this->ajaxGetRoom($content->jid, false, true);
        }
    }

    public function onRoomConfigError($packet)
    {
        Toast::send($packet->content);
    }

    public function onRoomConfig($packet)
    {
        list($config, $room) = array_values($packet->content);

        $view = $this->tpl();

        $xml = new \XMPPtoForm;
        $form = $xml->getHTML($config->x);

        $view->assign('form', $form);
        $view->assign('room', $room);

        Dialog::fill($view->draw('_chat_config_room'), true);
    }

    public function onRoomConfigSaved($packet)
    {
        $r = new DiscoRequest;
        $r->setTo($packet->content)
          ->request();

        Toast::send($this->__('chatroom.config_saved'));
    }

    private function setState(string $jid, string $message, $first = true)
    {
        if ($first) {
            $this->rpc('MovimUtils.removeClass', '#' . cleanupId($jid.'_state'), 'first');
        }
        $this->rpc('MovimTpl.fill', '#' . cleanupId($jid.'_state'), $message);
    }

    public function ajaxInit()
    {
        $view = $this->tpl();
        $date = $view->draw('_chat_date');
        $separator = $view->draw('_chat_separator');

        $this->rpc('Chat.setGeneralElements', $date, $separator);
    }

    public function ajaxClearCounter($jid)
    {
        $this->prepareMessages($jid, false, true);
        $this->event('chat_counter', $this->user->unreads());
    }

    /**
     * Get the header
     */
    public function ajaxGetHeader($jid, $muc = false)
    {
        $this->rpc(
            'MovimTpl.fill',
            '#' . cleanupId($jid.'_header'),
            $this->prepareHeader($jid, $muc)
        );

        $chatStates = ChatStates::getInstance();
        $this->onChatState($chatStates->getState($jid), false);
    }

    /**
     * @brief Get a discussion
     * @param string $jid
     */
    public function ajaxGet($jid = null, $light = false)
    {
        if ($jid == null) {
            $this->rpc('MovimTpl.hidePanel');
            $this->rpc('Notification.current', 'chat');
            $this->rpc('MovimUtils.pushState', $this->route('chat'));
            if ($light == false) {
                $this->rpc('MovimTpl.fill', '#chat_widget', $this->prepareEmpty());
            }
        } else {
            if ($light == false) {
                $this->rpc('MovimUtils.pushState', $this->route('chat', $jid));
                $this->rpc('MovimTpl.fill', '#chat_widget', $this->prepareChat($jid));

                $chatStates = ChatStates::getInstance();
                $this->onChatState($chatStates->getState($jid), false);

                $this->rpc('MovimTpl.showPanel');
                $this->rpc('Chat.focus');
            }

            $this->prepareMessages($jid);
            $this->rpc('Notification.current', 'chat|'.$jid);
            $this->rpc('Notification.clearAndroid', $this->route('chat', [$jid]));
            $this->rpc('Chat.scrollToSeparator');
        }
    }

    /**
     * @brief Get a chatroom
     * @param string $jid
     */
    public function ajaxGetRoom($room, $light = false, $noConnect = false)
    {
        if (!$this->validateJid($room)) {
            return;
        }

        $r = $this->user->session->conferences()->where('conference', $room)->first();

        if ($r) {
            if (!$r->connected && !$noConnect) {
                $this->rpc('Rooms_ajaxJoin', $r->conference, $r->nick);
            }

            if ($light == false) {
                $this->rpc('MovimUtils.pushState', $this->route('chat', [$room, 'room']));
                $this->rpc('MovimTpl.fill', '#chat_widget', $this->prepareChat($room, true));

                $chatStates = ChatStates::getInstance();
                $this->onChatState($chatStates->getState($room), false);

                $this->rpc('MovimTpl.showPanel');
                $this->rpc('Chat.focus');
            }

            $this->prepareMessages($room, true);
            $this->rpc('Notification.current', 'chat|'.$room);
            $this->rpc('Notification.clearAndroid', $this->route('chat', [$room, 'room']));
            $this->rpc('Chat.scrollToSeparator');
        } else {
            $this->rpc('RoomsUtils_ajaxAdd', $room);
        }
    }

    /**
     * @brief Send a message
     *
     * @param string $to
     * @param string $message
     * @return void
     */
    public function ajaxHttpDaemonSendMessage($to, $message = false, $muc = false,
        $resource = false, $replace = false, $file = false, $replyToMid = false)
    {
        $message = trim($message);
        $resolvedFile = resolvePictureFileFromUrl($message);

        if ($resolvedFile != false) $file = $resolvedFile;

        $body = ($file != false && $file->type != 'xmpp')
            ? $file->uri
            : $message;

        if ($body == '' || $body == '/me') {
            return;
        }

        $oldid = null;

        if ($replace) {
            $oldid = $replace->id;

            $m = $replace;
            $m->id = generateUUID();

            \App\Message::where('id', $oldid)->update([
                'id' => $m->id,
                'replaceid' => $m->id
            ]);
        } else {
            $m = new \App\Message;
            $m->id          = generateUUID();
            $m->thread      = generateUUID();
            $m->originid    = generateUUID();
            $m->replaceid   = $m->id;
            $m->user_id     = $this->user->id;
            $m->jidto       = echapJid($to);
            $m->jidfrom     = $this->user->id;
            $m->published   = gmdate('Y-m-d H:i:s');
        }

        if ($replyToMid) {
            $reply = $this->user->messages()
                          ->where('mid', $replyToMid)
                          ->first();

            if ($reply) {
                // See https://xmpp.org/extensions/xep-0201.html#new
                $m->thread = $reply->thread;
                $m->parentmid = $reply->mid;
            }
        }

        // TODO: make this boolean configurable
        $m->markable = true;
        $m->seen = true;

        $m->type    = 'chat';
        $m->resource = $this->user->session->resource;

        if ($muc) {
            $m->type        = 'groupchat';
            $m->resource    = $this->user->session->username;
            $m->jidfrom     = $to;
        }

        $m->body      = $body;

        if ($resource != false) {
            $to = $to . '/' . $resource;
        }

        // We decode URL codes to send the correct message to the XMPP server
        $p = new Publish;
        $p->setTo($to);
        //$p->setHTML($m->html);
        $p->setContent($m->body);

        if ($replace != false) {
            $p->setReplace($oldid);
        }

        $p->setId($m->id);
        $p->setThreadid($m->thread);
        $p->setOriginid($m->originid);

        if ($muc) {
            $p->setMuc();
        }

        if ($file) {
            $m->file = (array)$file;
            $p->setFile($file);
        }

        (ChatOwnState::getInstance())->halt();

        $p->request();

        /* Is it really clean ? */
        if (!$p->getMuc()) {
            $m->oldid = $oldid;
            $m->body = htmlentities(trim($m->body), ENT_XML1, 'UTF-8');
            $m->save();
            $m = $m->fresh();

            $packet = new \Moxl\Xec\Payload\Packet;
            $packet->content = $m;

            // We refresh the Chats list
            $c = new Chats;
            $c->onMessage($packet);

            $this->onMessage($packet);
        }
    }

    /**
     * @brief Send a correction message
     *
     * @param string $to
     * @param string $message
     * @return void
     */
    public function ajaxHttpDaemonCorrect($to, $message, $mid)
    {
        $replace = $this->user->messages()
                        ->where('mid', $mid)
                        ->first();

        if ($replace) {
            $this->ajaxHttpDaemonSendMessage($to, $message, false, false, $replace);
        }
    }

    /**
     * @brief Send a reaction
     */
    public function ajaxHttpDaemonSendReaction($mid, string $emoji)
    {
        $parentMessage = $this->user->messages()
                        ->where('mid', $mid)
                        ->first();

        $emojiHandler = \Movim\Emoji::getInstance();
        $emojiHandler->replace($emoji);

        if ($parentMessage && $emojiHandler->isSingleEmoji()) {
            // Try to load the MUC presence and resolve the resource
            $mucPresence = null;
            if ($parentMessage->type == 'groupchat') {
                $mucPresence = $this->user->session->presences()
                                    ->where('jid', $parentMessage->jidfrom)
                                    ->where('mucjid', $this->user->id)
                                    ->where('muc', true)
                                    ->first();

                if (!$mucPresence) return;
            }

            $jidfrom = ($parentMessage->type == 'groupchat')
                ? $mucPresence->resource
                : $this->user->id;

            $emojis = $parentMessage->reactions()
                ->where('jidfrom', $jidfrom)
                ->get();

            $r = new Reactions;
            $newEmojis = [];

            // This reaction was not published yet
            if ($emojis->where('emoji', $emoji)->count() == 0) {
                $reaction = new Reaction;
                $reaction->message_mid = $parentMessage->mid;
                $reaction->jidfrom = ($parentMessage->type == 'groupchat')
                    ? $this->user->session->username
                    : $this->user->id;
                $reaction->emoji = $emoji;

                if ($parentMessage->type != 'groupchat') {
                    $reaction->save();
                }

                $newEmojis = $emojis->push($reaction);
            } else {
                if ($parentMessage->type != 'groupchat') {
                    $parentMessage->reactions()
                        ->where('jidfrom', $jidfrom)
                        ->where('emoji', $emoji)
                        ->delete();
                }

                $newEmojis = $emojis->filter(function ($value, $key) use ($emoji) {
                    return $value->emoji != $emoji;
                });
            }

            $r->setTo($parentMessage->jidfrom != $parentMessage->user_id
                ? $parentMessage->jidfrom
                : $parentMessage->jidto)
              ->setId(\generateUUID())
              ->setParentId($parentMessage->replaceid)
              ->setReactions($newEmojis->pluck('emoji')->toArray());

            if ($parentMessage->type == 'groupchat') {
                $r->setMuc();
            }

            $r->request();

            if ($parentMessage->type != 'groupchat') {
                $packet = new \Moxl\Xec\Payload\Packet;
                $packet->content = $parentMessage;
                $this->onMessage($packet);
            }
        }
    }

    /**
     * @brief Refresh a message
     */
    public function ajaxRefreshMessage(string $mid)
    {
        $message = $this->user->messages()
                              ->where('mid', $mid)
                              ->first();

        if ($message) {
            $this->rpc('Chat.appendMessagesWrapper', $this->prepareMessage($message, null));
        }
    }

    /**
     * @brief Get the last message sent
     *
     * @param string $to
     * @return void
     */
    public function ajaxLast($to)
    {
        $m = $this->user->messages()
                        ->where('jidto', $to)
                        ->orderBy('published', 'desc')
                        ->first();

        if (!isset($m->sticker)
        && !isset($m->file)) {
            $this->rpc('Chat.setTextarea', htmlspecialchars_decode($m->body), $m->mid);
        }
    }

    /**
     * @brief Get the a sent message
     *
     * @param string $mid
     * @return void
     */
    public function ajaxEdit($mid)
    {
        $m = $this->user->messages()
                        ->where('mid', $mid)
                        ->first();

        if (!isset($m->sticker)
        && !isset($m->file)) {
            $this->rpc('Chat.setTextarea', htmlspecialchars_decode($m->body), $mid);
        }
    }

    /**
     * @brief Reply to a message
     *
     * @param string $mid
     * @return void
     */
    public function ajaxHttpDaemonReply($mid)
    {
        $m = $this->user->messages()
                        ->where('mid', $mid)
                        ->first();

        if (isset($m->thread)) {
            $view = $this->tpl();
            $view->assign('message', $m);
            $this->rpc('MovimTpl.fill', '#reply', $view->draw('_chat_reply'));
            $this->rpc('Chat.focus');
        }
    }

    /**
     * Clear the Reply box
     */
    public function ajaxClearReply()
    {
        $this->rpc('MovimTpl.fill', '#reply', '');
    }

    /**
     * @brief Send a "composing" message
     *
     * @param string $to
     * @return void
     */
    public function ajaxSendComposing($to, $muc = false)
    {
        if (!$this->validateJid($to)) {
            return;
        }

        (ChatOwnState::getInstance())->composing($to, $muc);
    }

    /**
     * @brief Get the chat history
     *
     * @param string jid
     * @param string time
     */
    public function ajaxGetHistory($jid, $date, $muc = false, $prepend = true)
    {
        if (!$this->validateJid($jid)) {
            return;
        }

        $messages = \App\Message::jid($jid)
            ->where('published', $prepend ? '<' : '>', date(MOVIM_SQL_DATE, strtotime($date)));


        $messages = $muc
            ? $messages->where('type', 'groupchat')->whereNull('subject')
            : $messages->whereIn('type', $this->_messageTypes);

        $messages = $messages->orderBy('published', 'desc')
                             ->withCount('reactions')
                             ->take($this->_pagination)
                             ->get();

        if ($messages->count() > 0) {
            if ($prepend) {
                Toast::send($this->__('message.history', $messages->count()));
            } else {
                $messages = $messages->reverse();
            }

            foreach ($messages as $message) {
                if (!$message->encrypted) {
                    $this->prepareMessage($message);
                }
            }

            $this->rpc('Chat.appendMessagesWrapper', $this->_wrapper, $prepend);
            $this->_wrapper = [];
        }
    }

    /**
     * @brief Configure a room
     *
     * @param string $room
     */
    public function ajaxGetRoomConfig($room)
    {
        if (!$this->validateJid($room)) {
            return;
        }

        $gc = new GetConfig;
        $gc->setTo($room)
           ->request();
    }

    /**
     * @brief Save the room configuration
     *
     * @param string $room
     */
    public function ajaxSetRoomConfig($data, $room)
    {
        if (!$this->validateJid($room)) {
            return;
        }

        $sc = new SetConfig;
        $sc->setTo($room)
           ->setData($data)
           ->request();
    }

    /**
     * @brief Set last displayed message
     */
    public function ajaxDisplayed($jid, $id)
    {
        if (!$this->validateJid($jid)) {
            return;
        }

        $message = $this->user->messages()->where('id', $id)->first();

        if ($message
        && $message->markable == true
        && $message->displayed == null) {
            $message->displayed = gmdate('Y-m-d H:i:s');
            $message->save();

            \Moxl\Stanza\Message::displayed($jid, $message->replaceid);
        }
    }

    /**
     * @brief Ask to clear the history
     *
     * @param string $jid
     */
    public function ajaxClearHistory($jid)
    {
        $view = $this->tpl();
        $view->assign('jid', $jid);
        $view->assign('count', \App\Message::jid($jid)->count());

        Dialog::fill($view->draw('_chat_clear'));
    }

    /**
     * @brief Clear the history
     *
     * @param string $jid
     */
    public function ajaxClearHistoryConfirm($jid)
    {
        if (!$this->validateJid($jid)) {
            return;
        }

        \App\Message::whereIn('id', function ($query) use ($jid) {
            $jidFromToMessages = DB::table('messages')
                ->where('user_id', $this->user->id)
                ->where('jidfrom', $jid)
                ->unionAll(DB::table('messages')
                    ->where('user_id', $this->user->id)
                    ->where('jidto', $jid)
                );

            $query->select('id')->from(
                $jidFromToMessages,
                'messages'
            )->where('user_id', $this->user->id);
        })->delete();

        $this->ajaxGet($jid);
    }

    public function prepareChat($jid, $muc = false)
    {
        $view = $this->tpl();

        $view->assign('jid', $jid);
        $view->assign('muc', $muc);
        $view->assign('emoji', prepareString('😀'));

        return $view->draw('_chat');
    }

    public function prepareMessages($jid, $muc = false, $seenOnly = false)
    {
        if (!$this->validateJid($jid)) {
            return;
        }

        $jid = echapJid($jid);

        $messagesQuery = \App\Message::jid($jid);

        $messagesQuery = $muc
            ? $messagesQuery->where('type', 'groupchat')->whereNull('subject')
            : $messagesQuery->whereIn('type', $this->_messageTypes);

        /**
         * The object need to be cloned there for MySQL, looks like the pagination/where is kept somewhere in between…
         **/
        $messagesRequest = clone $messagesQuery;
        $messagesCount = clone $messagesQuery;

        $messages = $messagesRequest->withCount('reactions')->orderBy('published', 'desc')->take($this->_pagination)->get();
        $unreadsCount = $messagesCount->where('seen', false)->count();

        if ($unreadsCount > 0) {
            $messagesClear = clone $messagesQuery;
            // Two queries as Eloquent doesn't seems to map correctly the parameters
            \App\Message::whereIn('mid', $messagesClear->where('seen', false)->pluck('mid'))->update(['seen' => true]);
        }

        // Prepare the muc presences if possible
        $firstMessage = $messages->first();
        if ($firstMessage && $firstMessage->type == 'groupchat') {
            $this->_mucPresences = $this->user->session->presences()
                ->where('jid', $firstMessage->jidfrom)
                ->where('muc', true)
                ->whereIn('resource', $messages->pluck('resource')->unique())
                ->get()
                ->keyBy(function($presence) {
                    return $presence->jid.$presence->resource;
                });
        }

        if (!$seenOnly) {
            $messages = $messages->reverse();

            foreach ($messages as $message) {
                $this->prepareMessage($message);
            }

            $view = $this->tpl();
            $view->assign('jid', $jid);

            $view->assign('contact', \App\Contact::firstOrNew(['id' => $jid]));
            $view->assign('me', false);
            $view->assign('muc', $muc);
            $left = $view->draw('_chat_bubble');

            $view->assign('contact', \App\Contact::firstOrNew(['id' => $this->user->id]));
            $view->assign('me', true);
            $view->assign('muc', $muc);
            $right = $view->draw('_chat_bubble');

            $this->rpc('Chat.setSpecificElements', $left, $right);
            $this->rpc('Chat.appendMessagesWrapper', $this->_wrapper, false);
        }

        $this->event($muc ? 'chat_open_room' : 'chat_open', $jid);
        $this->event('chat_counter', $this->user->unreads());

        $this->rpc('Chat.insertSeparator', $unreadsCount);
    }

    public function prepareMessage(&$message, $jid = null)
    {
        if ($jid != $message->jidto && $jid != $message->jidfrom && $jid != null) {
            return $this->_wrapper;
        }

        $message->jidto = echapJS($message->jidto);
        $message->jidfrom = echapJS($message->jidfrom);

        $emoji = \Movim\Emoji::getInstance();

        // URL messages
        $message->url = filter_var(trim($message->body), FILTER_VALIDATE_URL);

        // If the message doesn't contain a file but is a URL, we try to resolve it
        if (!$message->file && $message->url && $message->resolved == false) {
            $this->rpc('Chat.resolveMessage', (int)$message->mid);
        }

        if ($message->retracted) {
            $message->body = '<i class="material-icons">delete</i> '.__('message.retracted');
        } elseif ($message->encrypted) {
            $message->body = '<i class="material-icons">lock</i> '.__('message.encrypted');
        } elseif (isset($message->html) && !isset($message->file)) {
            $message->body = $message->html;
        } else {
            $message->addUrls();
            $message->body = $emoji->replace($message->body);
            $message->body = addHFR($message->body);
        }

        if (isset($message->subject) && $message->type == 'headline') {
            $message->body = $message->subject .': '. $message->body;
        }

        // Sticker message
        if (isset($message->sticker)) {
            $p = new Picture;
            $sticker = $p->get($message->sticker, false, false, 'png');
            $stickerSize = $p->getSize();

            if ($sticker == false
            && $message->jidfrom != $message->session) {
                $r = new Request;
                $r->setTo($message->jidfrom)
                    ->setResource($message->resource)
                    ->setCid($message->sticker)
                    ->request();
            } else {
                $message->sticker = [
                    'url' => $sticker,
                    'width' => $stickerSize['width'],
                    'height' => $stickerSize['height']
                ];
            }
        }

        // Jumbo emoji
        if ($emoji->isSingleEmoji()
            && !isset($message->html)
            && in_array($message->type,  ['chat', 'groupchat'])) {
            $message->sticker = [
                'url' => $emoji->getLastSingleEmojiURL(),
                'title' => ':'.$emoji->getLastSingleEmojiTitle().':',
                'height' => 60,
            ];
        }

        // Attached file
        if (isset($message->file)) {
            // We proxify pictures links even if they are advertized as small ones
            if (\array_key_exists('type', $message->file)
            && typeIsPicture($message->file['type'])
            && $message->file['size'] <= SMALL_PICTURE_LIMIT) {
                $message->sticker = [
                    'thumb' => $this->route('picture', urlencode($message->file['uri'])),
                    'url' => $message->file['uri'],
                    'picture' => true
                ];
            }

            $url = parse_url($message->file['uri']);
            // Other image websites
            if (\array_key_exists('host', $url)) {
                switch ($url['host']) {
                    case 'i.imgur.com':
                        $thumb = getImgurThumbnail($message->file['uri']);

                        if ($thumb) {
                            $message->sticker = [
                                'url' => $message->file['uri'],
                                'thumb' => $thumb,
                                'picture' => true
                            ];
                        }
                        break;
                }
            }

            // Build cards for the URIs
            $uri = explodeXMPPURI($message->file['uri']);

            switch ($uri['type']) {
                case 'post':
                    $post = \App\Post::where('server', $uri['params'][0])
                        ->where('node',  $uri['params'][1])
                        ->where('nodeid',  $uri['params'][2])
                        ->first();

                    if ($post) {
                        $p = new Post;
                        $message->card = $p->prepareTicket($post);
                    }
                    break;
            }
        }

        // Parent
        if ($message->parent) {
            if ($message->parent->file) {
                $message->parent->body = (typeIsPicture($message->parent->file['type']))
                    ? '<i class="material-icons">image</i> '.__('chats.picture')
                    : '<i class="material-icons">insert_drive_file</i> '.__('avatar.file');
            }

            // Resolve the parent from

            if ($message->parent->type == 'groupchat') {
                $message->parent->resolveColor();
                $message->parent->fromName = $message->parent->resource;
            } else {
                // TODO optimize
                $roster = $this->user->session->contacts()
                            ->where('jid', $message->parent->jidfrom)
                            ->first();

                $contactFromName = $message->parent->from
                    ? $message->parent->from->truename
                    : $message->parent->jidfrom;

                $message->parent->fromName = $roster
                    ? $roster->truename
                    : $contactFromName;
            }
        }

        // reactions_count if cached, if not, reload it from the DB
        if ($message->reactions_count ?? $message->reactions()->count()) {
            $message->reactionsHtml = $this->prepareReactions($message);
        }

        $message->rtl = isRTL($message->body);
        $message->publishedPrepared = prepareTime(strtotime($message->published));

        if ($message->delivered) {
            $message->delivered = prepareDate(strtotime($message->delivered), true);
        }

        if ($message->displayed) {
            $message->displayed = prepareDate(strtotime($message->displayed), true);
        }

        $date = prepareDate(strtotime($message->published), false, false, true);

        if (empty($date)) {
            $date = $this->__('date.today');
        }

        // We create the date wrapper
        if (!array_key_exists($date, $this->_wrapper)) {
            $this->_wrapper[$date] = [];
        }

        $messageDBSeen = $message->seen;
        $n = new Notification;

        if ($message->type == 'groupchat') {
            $message->resolveColor();

            // Cache the resolved presences for a while
            $key = $message->jidfrom.$message->resource;
            if (!isset($this->_mucPresences[$key])) {
                $this->_mucPresences[$key] = $this->user->session->presences()
                           ->where('jid', $message->jidfrom)
                           ->where('resource', $message->resource)
                           ->where('muc', true)
                           ->first();
            }

            if ($this->_mucPresences[$key] && $this->_mucPresences[$key] !== true) {
                if ($url = $this->_mucPresences[$key]->conferencePicture) {
                    $message->icon_url = $url;
                }

                $message->moderator = ($this->_mucPresences[$key]->mucrole == 'moderator');
                $message->mine = $message->seen = ($this->_mucPresences[$key]->mucjid == $this->user->id);

            } else {
                $this->_mucPresences[$key] = true;
            }

            $message->icon = firstLetterCapitalize($message->resource);
        }

        if($message->seen === false) {
            $message->seen = ('chat|'.$message->jidfrom == $n->getCurrent());
        }

        if ($message->seen === true
        && $messageDBSeen === false) {
            $this->user->messages()
                 ->where('id', $message->id)
                 ->update(['seen' => true]);
        }

        $msgkey = '<' . $message->jidfrom;
        $msgkey .= ($message->type == 'groupchat') ? cleanupId($message->resource, true) : '';
        $msgkey .= '>' . substr($message->published, 11, 5);

        $counter = count($this->_wrapper[$date]);

        $this->_wrapper[$date][$counter.$msgkey] = $message;

        if ($message->type == 'invitation') {
            $view = $this->tpl();
            $view->assign('message', $message);
            $message->body = $view->draw('_chat_invitation');
        }

        if ($message->type == 'jingle_start') {
            $view = $this->tpl();
            $view->assign('message', $message);
            $message->body = $view->draw('_chat_jingle_start');
        }

        if ($message->type == 'jingle_end') {
            $view = $this->tpl();
            $view->assign('message', $message);
            $view->assign('diff', false);

            $start = Message::where(
                [
                    'type' =>'jingle_start',
                    'thread'=> $message->thread
                ]
            )->first();

            if ($start) {
                $diff = (new DateTime($start->created_at))
                  ->diff(new DateTime($message->created_at));

                $view->assign('diff', $diff);
            }

            $message->body = $view->draw('_chat_jingle_end');
        }

        return $this->_wrapper;
    }

    public function prepareReactions(Message $message)
    {
        $view = $this->tpl();
        $merged = [];

        $reactions = $message
            ->reactions()
            ->orderBy('created_at')
            ->get();

        foreach ($reactions as $reaction) {
            if (!array_key_exists($reaction->emoji, $merged)) {
                $merged[$reaction->emoji] = [];
            }

            $merged[$reaction->emoji][] = $reaction->jidfrom;
        }

        $view->assign('message', $message);
        $view->assign('reactions', $merged);
        $view->assign('me', $this->user->id);

        return $view->draw('_chat_reactions');
    }

    public function prepareHeader($jid, $muc = false)
    {
        $view = $this->tpl();

        $view->assign('jid', $jid);
        $view->assign('muc', $muc);
        $view->assign(
            'info',
            \App\Info::where('server', $this->user->session->host)
                     ->where('node', '')
                     ->first()
        );
        $view->assign('anon', false);

        if ($muc) {
            $view->assign('conference', $this->user->session->conferences()
                                             ->where('conference', $jid)
                                             ->with('info')
                                             ->first());

            $mucinfo = \App\Info::where('server', explodeJid($jid)['server'])
                                ->where('node', '')
                                ->first();
            if ($mucinfo && !empty($mucinfo->abuseaddresses)) {
                $view->assign('info', $mucinfo);
            }
        } else {
            $view->assign('roster', $this->user->session->contacts()->where('jid', $jid)->first());
            $view->assign('contact', \App\Contact::firstOrNew(['id' => $jid]));
        }

        return $view->draw('_chat_header');
    }

    public function prepareEmpty()
    {
        $view = $this->tpl();

        $chats = \App\Cache::c('chats');
        if ($chats == null) {
            $chats = [];
        }
        $chats[$this->user->id] = true;

        $top = $this->user->session->topContacts()
            ->join(DB::raw('(
                select min(value) as value, jid as pjid
                from presences
                group by jid) as presences
            '), 'presences.pjid', '=', 'rosters.jid')
            ->where('value', '<', 5)
            ->whereNotIn('rosters.jid', array_keys($chats))
            ->with('presence.capability')
            ->take(16)
            ->get();
        $view->assign('top', $top);

        return $view->draw('_chat_empty');
    }

    private function prepareComposeList(array $list)
    {
        $view = $this->tpl();
        $view->assign('list', implode(', ', $list));
        return $view->draw('_chat_compose_list');
    }

    /**
     * @brief Validate the jid
     *
     * @param string $jid
     */
    private function validateJid($jid)
    {
        return (Validator::stringType()->noWhitespace()->length(6, 256)->validate($jid));
    }

    public function getSmileyPath($id)
    {
        return getSmileyPath($id);
    }

    public function display()
    {
        $this->view->assign('pagination', $this->_pagination);
    }
}
