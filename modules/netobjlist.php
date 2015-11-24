<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2013 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

$layout['pagetitle'] = trans('Network Object');

if(!isset($_GET['o']))
	$SESSION->restore('ndlo', $o);
else
	$o = $_GET['o'];
$SESSION->save('ndlo', $o);

if(!isset($_GET['s']))
	$SESSION->restore('ndfs', $s);
else
	$s = $_GET['s'];
$SESSION->save('ndfs', $s);

if(!isset($_GET['p']))
	$SESSION->restore('ndfp', $p);
else
	$p = $_GET['p'];
$SESSION->save('ndfp', $p);

if(!isset($_GET['n']))
	$SESSION->restore('ndfn', $n);
else
	$n = $_GET['n'];
$SESSION->save('ndfn', $n);

$netdevlist = $LMS->GetNetDevList($o, $search);
$listdata['total'] = $netdevlist['total'];
$listdata['order'] = $netdevlist['order'];
$listdata['direction'] = $netdevlist['direction'];
$listdata['status'] = $s;
$listdata['invprojectid'] = $p;
$listdata['netnode'] = $n;
$listdata['producer'] = $producer;
$listdata['model'] = $model;
unset($netdevlist['total']);
unset($netdevlist['order']);
unset($netdevlist['direction']);

if(!isset($_GET['page']))
        $SESSION->restore('ndlp', $_GET['page']);
	
$page = (! $_GET['page'] ? 1 : $_GET['page']);
$pagelimit = ConfigHelper::getConfig('phpui.nodelist_pagelimit', $listdata['total']);
$start = ($page - 1) * $pagelimit;

$SESSION->save('ndlp', $page);

$SESSION->save('backto', $_SERVER['QUERY_STRING']);

$SMARTY->assign('page',$page);
$SMARTY->assign('pagelimit',$pagelimit);
$SMARTY->assign('start',$start);
$SMARTY->assign('netdevlist',$netdevlist);
$SMARTY->assign('listdata',$listdata);
$SMARTY->assign('netnodes', $DB->GetAll("SELECT id, name FROM netnodes ORDER BY name"));
$SMARTY->assign('NNprojects', $DB->GetAll("SELECT * FROM invprojects WHERE type<>? ORDER BY name",
	array(INV_PROJECT_SYSTEM)));
$SMARTY->assign('producers', $producers);
$SMARTY->assign('models', $models);
$SMARTY->display('netobj/netobjlist.html');

?>
