<?php
/*
 * GNU Social - a federating social network
 * Copyright (C) 2013, Free Software Foundation, Inc.
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
 * Implements WebFinger for GNU Social, as well as support for the
 * '.well-known/host-meta' resource.
 *
 * Depends on: LRDD plugin
 *
 * @package GNUsocial
 * @author  Mikael Nordfeldth <mmn@hethane.se>
 */

if (!defined('GNUSOCIAL')) { exit(1); }

class WebFingerPlugin extends Plugin
{
    public function onRouterInitialized($m)
    {
        $m->connect('.well-known/host-meta', array('action' => 'hostmeta'));
        $m->connect('.well-known/host-meta.:format',
                        array('action' => 'hostmeta',
                              'format' => '(xml|json)'));
        // the resource GET parameter can be anywhere, so don't mention it here
        $m->connect('.well-known/webfinger', array('action' => 'webfinger'));
        $m->connect('.well-known/webfinger.:format',
                        array('action' => 'webfinger',
                              'format' => '(xml|json)'));
        $m->connect('main/ownerxrd', array('action' => 'ownerxrd'));
        return true;
    }

    public function onLoginAction($action, &$login)
    {
        switch ($action) {
        case 'hostmeta':
        case 'webfinger':
            $login = true;
            return false;
        }
        
        return true;
    }

    public function onStartGetProfileAcctUri(Profile $profile, &$acct)
    {
        $wfr = new WebFingerResource_Profile($profile);
        try {
            $acct = $wfr->reconstructAcct();
        } catch (Exception $e) {
            return true;
        }

        return false;
    }

    public function onEndGetWebFingerResource($resource, WebFingerResource &$target=null, array $args=array())
    {
        $profile = null;
        if (Discovery::isAcct($resource)) {
            $parts = explode('@', substr(urldecode($resource), 5)); // 5 is strlen of 'acct:'
            if (count($parts) == 2) {
                list($nick, $domain) = $parts;
                if ($domain === common_config('site', 'server')) {
                    $nick = common_canonical_nickname($nick);
                    $user = User::getKV('nickname', $nick);
                    if (!($user instanceof User)) {
                        throw new NoSuchUserException(array('nickname'=>$nick));
                    }
                    $profile = $user->getProfile();
                } else {
                    throw new Exception(_('Remote profiles not supported via WebFinger yet.'));
                }
            }
        } else {
            $user = User::getKV('uri', $resource);
            if ($user instanceof User) {
                $profile = $user->getProfile();
            } else {
                // try and get it by profile url
                $profile = Profile::getKV('profileurl', $resource);
            }
        }

        if ($profile instanceof Profile) {
            $target = new WebFingerResource_Profile($profile);
            return false;   // We got our target, stop handler execution
        }

        $notice = Notice::getKV('uri', $resource);
        if ($notice instanceof Notice) {
            $target = new WebFingerResource_Notice($notice);
            return false;
        }

        return true;
    }

    public function onStartHostMetaLinks(array &$links)
    {
        foreach (Discovery::supportedMimeTypes() as $type) {
            $links[] = new XML_XRD_Element_Link(Discovery::LRDD_REL,
                            common_local_url('webfinger') . '?resource={uri}',
                            $type,
                            true);    // isTemplate
        }
    }

    /**
     * Add a link header for LRDD Discovery
     */
    public function onStartShowHTML($action)
    {
        if ($action instanceof ShowstreamAction) {
            $acct = 'acct:'. $action->profile->nickname .'@'. common_config('site', 'server');
            $url = common_local_url('webfinger') . '?resource='.$acct;

            foreach (array(Discovery::JRD_MIMETYPE, Discovery::XRD_MIMETYPE) as $type) {
                header('Link: <'.$url.'>; rel="'. Discovery::LRDD_REL.'"; type="'.$type.'"');
            }
        }
    }

    public function onPluginVersion(&$versions)
    {
        $versions[] = array('name' => 'WebFinger',
                            'version' => GNUSOCIAL_VERSION,
                            'author' => 'Mikael Nordfeldth',
                            'homepage' => 'http://www.gnu.org/software/social/',
                            // TRANS: Plugin description.
                            'rawdescription' => _m('Adds WebFinger lookup to GNU Social'));

        return true;
    }
}
