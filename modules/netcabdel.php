<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2013 LMS Cabelopers
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

if (!$LMS->NetCabExists($_GET['id']))
	$SESSION->redirect('?m=netcablist');

$layout['pagetitle'] = trans('Deletion of Cable with ID: $a', sprintf('%04d', $_GET['id']));
$SMARTY->assign('netcabid', $_GET['id']);

if ($_GET['is_sure'] != 1) {
	$body = '<P>' . trans('Are you sure, you want to delete that cable?') . '</P>'; 
	$body .= '<P><A HREF="?m=netcabdel&id=' . $_GET['id'] . '&is_sure=1">' . trans('Yes, I am sure.') . '</A></P>';
} else {
	$body = '<P>' . trans('Cable has been deleted.') . '</P>';
	$LMS->DeleteNetCab($_GET['id']);
	$LMS->CleanupInvprojects();
	header('Location: ?m=netcablist');
}

$SMARTY->assign('body',$body);
$SMARTY->display('dialog.html');

?>