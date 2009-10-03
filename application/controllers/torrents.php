<?php
/*
 * rTorrentWeb version 0.1 prerelease
 * $Id$
 * Copyright (C) 2009, Daniel Lo Nigro (Daniel15) <daniel at d15.biz>
 * 
 * This file is part of rTorrentWeb.
 * 
 * rTorrentWeb is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * rTorrentWeb is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with rTorrentWeb.  If not, see <http://www.gnu.org/licenses/>.
 */
defined('SYSPATH') OR die('No direct access allowed.');

class Torrents_Controller extends Base_Controller
{
	public function __construct()
	{
		parent::__construct();
		$this->rtorrent = new Rtorrent;
	}
	public function index()
	{
		//new View('index');
		View::factory('listing')->render(true);
	}
	
	/**
	 * Refresh the torrent listing
	 */
	public function refresh()
	{
		if (false === ($results = $this->rtorrent->listing()))
			die(json_encode(array(
				'error' => true,
				'message' => $this->rtorrent->error()
			)));
			
		echo json_encode(array(
			'error' => false,
			'data' => $results,
		));
	}
	
	/**
	 * Get a list of the files in a particular torrent
	 */
	public function files($hash)
	{
		if (!($results = $this->rtorrent->files_tree($hash)))
			die(json_encode(array(
				'error' => true,
				'message' => $this->rtorrent->error()
			)));
			
		echo json_encode(array(
			'error' => false,
			'hash' => $hash,
			'files' => $results['files'],
			'dirs' => $results['dirs'],
		));
	}
	
	/**
	 * A few simple actions (pause, stop, start)
	 */
	public function action($action, $hash)
	{
		// The really simple ones with no return values. We just call directly
		// through to the rTorrent library
		if (in_array($action, array('pause', 'start', 'stop')))
		{
			$this->rtorrent->$action($hash);
			echo json_encode(array(
				'error' => false,
				'hash' => $hash
			));
		}
		// Otherwise, it's something more advanced? It'll have its own function,
		// let's just point them there.
		elseif (in_array($action, array('delete')))
		{
			$this->$action($hash);
		}
		else
		{
			echo json_encode(array(
				'error' => true,
				'message' => 'Unknown action',
				'hash' => $hash
			));
		}
	}
	
	/**
	 * Deleting a torrent :o
	 * Can only be called via action() handler above, so we know that the user is 
	 * authenticated to do stuff with this torrent!
	 * TODO: Delete data files too?
	 */
	private function delete($hash)
	{
		// Delete it from rTorrent
		$this->rtorrent->delete($hash);
		// Also delete it from the database
		ORM::factory('torrent', $hash)->delete();
		
		echo json_encode(array(
			'error' => false,
			'action' => 'delete',
			'hash' => $hash
		));
	}
	
	
	/** 
	 * Add a new torrent
	 */
	public function add()
	{
		// Did they actually submit the form?
		if (isset($_POST['submit']))
		{
			// Let's try this upload
			$_FILES = Validation::factory($_FILES)->
				add_rules('torrent_file', 'upload::valid', 'upload::type[torrent]')->
				add_callbacks('torrent_file', array($this, '_unique_torrent'));
			if (!$_FILES->validate())
			{
				// TODO: Proper error handling
				echo 'Some errors were encountered while adding your torrent:<br />
<ul>
	<li>', implode('</li>
	<li>', $_FILES->errors()), '</li>
</ul>';
				die();
			}
			
			// Better save it to a proper location
			$filename = upload::save('torrent_file', null, Kohana::config('config.metadata_dir'));
			$hash = $_FILES->torrent_file['hash'];			
			// Try to add it to rTorrent
			// TODO: Error checking
			$this->rtorrent->add($filename, $this->user->homedir);
			// Add this torrent into the database
			$torrent = ORM::factory('torrent');
			$torrent->hash = $hash;
			$torrent->private = (bool)$this->input->post('private');
			// This marks it as our torrent - Adds the torrent->user relation
			//$torrent->add($this->user);
			$torrent->user_id = $this->user->id;
			$torrent->save();
			// Now they need to add files for this torrent
			url::redirect('torrents/add_files/' . $hash);
		}
		else
		{
			$template = new View('template');
			$template->title = 'Add New Torrent';
			$template->content = new View('torrent/add');
			$template->content->homedir = $this->user->homedir;
			$template->render(true);
		}
	}
	
	/**
	 * Step 2 of adding a torrent - Choosing files for it
	 */
	public function add_files($hash)
	{
		// Is this not our torrent?
		if (!$this->_check_owner($hash))
			url::redirect('');
		
		// Did they actually submit?
		if (isset($_POST['submit']))
		{
			$priorities = array();
			// Get a list of all the files in the torrent
			// TODO: Is this really needed? I just need the count, really. :P
			$file_info = $this->rtorrent->files($hash);
			
			// Now we go through and see exactly what we have to enable
			foreach ($file_info as $file_id => &$file)
			{
				$priorities[$file_id] = isset($_POST['files'][$file_id]) ? 1 : 0;
				/*if (isset($_POST['files'][$file_id]))
					echo 'Enable ', $file['name'], '<br />';
				else
					echo 'Disable ', $file['name'], '<br />';*/
			}

			// Actually set the priorities
			$this->rtorrent->set_file_priorities($hash, $priorities);
			// Now, start the torrent
			$this->rtorrent->start($hash);
			url::redirect('');
		}
		// Get the files in this torrent
		$results = $this->rtorrent->files_tree($hash);
		
		$template = new View('template');
		$template->title = 'Add New Torrent &mdash; Step 2';
		$template->content = new View('torrent/add_files');
		$template->content->dirs = $results['dirs'];
		$template->content->files = $results['files'];
		$template->render(true);
	}
	
	/**
	 * Check if we own a torrent
	 */
	private function _check_owner($hash)
	{
		// Load this torrent
		$torrent = ORM::factory('torrent', $hash);
		// Does it even exist in the database? If not, assume we can do stuff 
		// with it TODO: Is this acceptable?
		if (!$torrent->loaded)
			return true;

		return $torrent->user_id == $this->user->id;
	}
	
	/**
	 * Check if a torrent already exists on the server
	 */
	public function _unique_torrent(Validation $validation, $field)
	{
		$file =& $validation[$field];

		// Calculate the hash of the torrent
		// Hash = SHA1 of the encoded torrent info
		// It's stored so we don't need to calculate it twice
		$torrent_data = new BDecode($file['tmp_name']);
		$bencode = new BEncode();
		$file['hash'] = strtoupper(bin2hex(sha1($bencode->encode($torrent_data->result['info']), true)));
		// Check this torrent
		if ($this->rtorrent->exists($file['hash']))
			$validation->add_error($field, 'torrent_exists');
	}
}
?>