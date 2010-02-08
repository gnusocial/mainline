<?php
/*
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2010, StatusNet, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Send a PuSH subscription verification from our internal hub.
 * Queue up final distribution for 
 * @package Hub
 * @author Brion Vibber <brion@status.net>
 */
class HubDistribQueueHandler extends QueueHandler
{
    function transport()
    {
        return 'hubdistrib';
    }

    function handle($notice)
    {
        assert($notice instanceof Notice);

        // See if there's any PuSH subscriptions, including OStatus clients.
        // @fixme handle group subscriptions as well
        // http://identi.ca/api/statuses/user_timeline/1.atom
        $feed = common_local_url('ApiTimelineUser',
                                 array('id' => $notice->profile_id,
                                       'format' => 'atom'));
        $sub = new HubSub();
        $sub->topic = $feed;
        if ($sub->find()) {
            common_log(LOG_INFO, "Preparing $sub->N PuSH distribution(s) for $feed");
            $qm = QueueManager::get();
            $atom = $this->userFeedForNotice($notice);
            while ($sub->fetch()) {
                common_log(LOG_INFO, "Prepping PuSH distribution to $sub->callback for $feed");
                $data = array('sub' => clone($sub),
                              'atom' => $atom);
                $qm->enqueue($data, 'hubout');
            }
        } else {
            common_log(LOG_INFO, "No PuSH subscribers for $feed");
        }
    }

    /**
     * Build a single-item version of the sending user's Atom feed.
     * @param Notice $notice
     * @return string
     */
    function userFeedForNotice($notice)
    {
        // @fixme this feels VERY hacky...
        // should probably be a cleaner way to do it

        ob_start();
        $api = new ApiTimelineUserAction();
        $api->prepare(array('id' => $notice->profile_id,
                            'format' => 'atom',
                            'max_id' => $notice->id,
                            'since_id' => $notice->id - 1));
        $api->showTimeline();
        $feed = ob_get_clean();
        
        // ...and override the content-type back to something normal... eww!
        // hope there's no other headers that got set while we weren't looking.
        header('Content-Type: text/html; charset=utf-8');

        common_log(LOG_DEBUG, $feed);
        return $feed;
    }
}
