<?php

/**
 * Zot endpoint
 */


require_once('include/zot.php');


function post_init(&$a) {

	// All other access to this endpoint is via the post method.
	// Here we will pick out the magic auth params which arrive
	// as a get request.

	if(argc() > 1) {

		$webbie = argv(1);

		if(array_key_exists('auth',$_REQUEST)) {

			$address = $_REQUEST['auth'];
			$dest    = $_REQUEST['dest'];
			$sec     = $_REQUEST['sec'];
			$version = $_REQUEST['version'];

			switch($dest) {
				case 'channel':
					$desturl = z_root() . '/channel/' . $webbie;
					break;
				case 'photos':
					$desturl = z_root() . '/photos/' . $webbie;
					break;
				case 'profile':
					$desturl = z_root() . '/profile/' . $webbie;
					break;
				default:
					$desturl = $dest;
					break;
			}
			$c = q("select * from channel where channel_address = '%s' limit 1",
				dbesc($webbie)
			);
			if(! $c) {
				logger('mod_zot: auth: unable to find channel ' . $webbie);
				// They'll get a notice when they hit the page, we don't need two of them. 
				goaway($desturl);
			}

			// Try and find a hubloc for the person attempting to auth
			$x = q("select * from hubloc left join xchan on xchan_hash = hubloc_hash where hubloc_addr = '%s' limit 1",
				dbesc($address)
			);

			if(! $x) {
				// finger them if they can't be found. 
				$ret = zot_finger($addr,null);
				if($ret['success']) {
					$j = json_decode($ret['body'],true);
					if($j)
						import_xchan($j);
					$x = q("select * from hubloc left join xchan on xchan_hash = hubloc_hash where hubloc_addr = '%s' limit 1",
						dbesc($address)
					);
				}
			}
			if(! $x) {
				logger('mod_zot: auth: unable to finger ' . $addr);
				goaway($desturl);
			}

			logger('mod_zot: auth request received from ' . $x[0]['xchan_addr'] . ' for ' . $webbie); 

			// check credentials and access

			// If they are already authenticated and haven't changed credentials, 
			// we can save an expensive network round trip and improve performance.

			$remote = remote_user();
			$result = null;

			$already_authed = ((($remote) && ($x[0]['hubloc_hash'] == $remote)) ? true : false); 

			if(! $already_authed) {
				// Auth packets MUST use ultra top-secret hush-hush mode 
				$p = zot_build_packet($c[0],$type = 'auth_check',
					array(array('guid' => $x[0]['hubloc_guid'],'guid_sig' => $x[0]['hubloc_guid_sig'])), 
					$x[0]['hubloc_sitekey'], $sec);
				$result = zot_zot($x[0]['hubloc_callback'],$p);
				if(! $result['success']) {
					logger('mod_zot: auth_check callback failed.');
					goaway($desturl);
				}
				$j = json_decode($result['body'],true);
			}

			if($already_authed || $j['result']) {
				// everything is good... maybe
				if(local_user()) {
					notice( t('Remote authentication blocked. You are logged into this site locally. Please logout and retry.') . EOL);
					goaway($desturl);
				}
				// log them in
				$_SESSION['authenticated'] = 1;
				$_SESSION['visitor_id'] = $x[0]['xchan_hash'];
				$a->set_observer($x[0]);
				require_once('include/security.php');
				$a->set_groups(init_groups_visitor($_SESSION['visitor_id']));
				info(sprintf( t('Welcome %s. Remote authentication successful.'),$x[0]['xchan_name']));
				logger('mod_zot: auth success from ' . $x[0]['xchan_addr'] . ' for ' . $webbie); 

			}

			goaway($desturl);
		}

		logger('mod_zot: invalid args: ' . print_r($a->argv,true));
		killme();
	}

	return;
}




	
function post_post(&$a) {

	logger('mod_zot: ' . print_r($_REQUEST,true), LOGGER_DEBUG);

	$ret = array('result' => false);

	$data = json_decode($_REQUEST['data'],true);

	logger('mod_zot: data: ' . print_r($data,true), LOGGER_DATA);

	if(array_key_exists('iv',$data)) {
		$data = aes_unencapsulate($data,get_config('system','prvkey'));
		logger('mod_zot: decrypt1: ' . $data, LOGGER_DATA);
		$data = json_decode($data,true);
	}

	logger('mod_zot: decoded data: ' . print_r($data,true), LOGGER_DATA);

	$msgtype = ((array_key_exists('type',$data)) ? $data['type'] : '');


	if($msgtype === 'pickup') {

		if((! $data['secret']) || (! $data['secret_sig'])) {
			$ret['message'] = 'no verification signature';
			logger('mod_zot: pickup: ' . $ret['message'], LOGGER_DEBUG);
			json_return_and_die($ret);
		}
		$r = q("select hubloc_sitekey from hubloc where hubloc_url = '%s' and hubloc_callback = '%s' and hubloc_sitekey != '' limit 1",
			dbesc($data['url']),
			dbesc($data['callback'])
		);
		if(! $r) {
			$ret['message'] = 'site not found';
			logger('mod_zot: pickup: ' . $ret['message']);
			json_return_and_die($ret);
		}
		// verify the url_sig
		$sitekey = $r[0]['hubloc_sitekey'];
		logger('sitekey: ' . $sitekey);

		if(! rsa_verify($data['callback'],base64url_decode($data['callback_sig']),$sitekey)) {
			$ret['message'] = 'possible site forgery';
			logger('mod_zot: pickup: ' . $ret['message']);
			json_return_and_die($ret);
		}

		if(! rsa_verify($data['secret'],base64url_decode($data['secret_sig']),$sitekey)) {
			$ret['message'] = 'secret validation failed';
			logger('mod_zot: pickup: ' . $ret['message']);
			json_return_and_die($ret);
		}

		// If we made it to here, we've got a valid pickup. Grab everything for this host and send it.

		$r = q("select outq_posturl from outq where outq_hash = '%s' and outq_posturl = '%s' limit 1",
			dbesc($data['secret']),
			dbesc($data['callback'])
		);
		if(! $r) {
			$ret['message'] = 'nothing to pick up';
			logger('mod_zot: pickup: ' . $ret['message']);
			json_return_and_die($ret);
		}

		$r = q("select * from outq where outq_posturl = '%s'",
			dbesc($data['callback'])
		);
		if($r) {
			$ret['success'] = true;
			$ret['pickup'] = array();
			foreach($r as $rr) {
				$ret['pickup'][] = array('notify' => json_decode($rr['outq_notify'],true),'message' => json_decode($rr['outq_msg'],true));

				$x = q("delete from outq where outq_hash = '%s' limit 1",
					dbesc($rr['outq_hash'])
				);
			}
		}
		$encrypted = aes_encapsulate(json_encode($ret),$sitekey);
		json_return_and_die($encrypted);
	}

	if(array_key_exists('sender',$data)) {
		$sender = $data['sender'];
	}	

	$hub = zot_gethub($sender);
	if(! $hub) {
		// (!!) this will validate the sender
		$result = zot_register_hub($sender);
		if((! $result['success']) || (! zot_gethub($sender))) {
			$ret['message'] = 'Hub not available.';
			logger('mod_zot: no hub');
			json_return_and_die($ret);
		}
	}

	// TODO: check which hub is primary and take action if mismatched

	if(array_key_exists('recipients',$data))
		$recipients = $data['recipients'];

	if($msgtype === 'refresh') {

		// remote channel info (such as permissions or photo or something)
		// has been updated. Grab a fresh copy and sync it.

		if($recipients) {

			// This would be a permissions update, typically for one connection

			foreach($recipients as $recip) {	
				$r = q("select channel.*,xchan.* from channel 
					left join xchan on channel_hash = xchan_hash
					where channel_guid = '%s' and channel_guid_sig = '%s' limit 1",
					dbesc($recip['guid']),
					dbesc($recip['guid_sig'])
				);

				$x = zot_refresh(array(
						'xchan_guid'     => $sender['guid'], 
						'xchan_guid_sig' => $sender['guid_sig'],
						'hubloc_url'     => $sender['url']
				),$r[0]);
			}
		}
		else {

			// system wide refresh
				
			$x = zot_refresh(array(
				'xchan_guid'     => $sender['guid'], 
				'xchan_guid_sig' => $sender['guid_sig'],
				'hubloc_url'     => $sender['url']
			),null);
		}
		$ret['result'] = true;
		json_return_and_die($ret);
	}

	if($msgtype === 'notify') {
		$async = get_config('system','queued_fetch');

		if($async) {
			// add to receive queue
			// qreceive_add($data);
		}
		else {
			$x = zot_fetch($data);
			$ret['delivery_report'] = $x;
		}

		$ret['result'] = true;
		json_return_and_die($ret);

	}

	if($msgtype === 'auth_check') {
		$arr = $data['sender'];
		$sender_hash = base64url_encode(hash('whirlpool',$arr['guid'] . $arr['guid_sig'], true));

		// garbage collect any old unused notifications
		q("delete from verify where type = 'auth' and created < UTC_TIMESTAMP() - INTERVAL 10 MINUTE");

		$y = q("select xchan_pubkey from xchan where xchan_hash = '%s' limit 1",
			dbesc($sender_hash)
		);
		// We created a unique hash in mod/magic.php when we invoked remote auth, and stored it in
		// the verify table. It is now coming back to us as 'secret' and is signed by the other site.
		// First verify their signature.

		if((! $y) || (! rsa_verify($data['secret'],base64url_decode($data['secret_sig']),$y[0]['xchan_pubkey']))) {
			logger('mod_zot: auth_check: sender not found or secret_sig invalid.');
			json_return_and_die($ret);
		}

		// There should be exactly one recipient
		if($data['recipients']) {

			$arr = $data['recipients'][0];
			$recip_hash = base64url_encode(hash('whirlpool',$arr['guid'] . $arr['guid_sig'], true));
			$c = q("select channel_id from channel where channel_hash = '%s' limit 1",
				dbesc($recip_hash)
			);
			if(! $c) {
				logger('mod_zot: auth_check: recipient channel not found.');
				json_return_and_die($ret);
			}

			// This additionally checks for forged senders since we already stored the expected result in meta
			// and we've already verified that this is them via zot_gethub() and that their key signed our token

			$z = q("select id from verify where channel = %d and type = 'auth' and token = '%s' and meta = '%s' limit 1",
				intval($c[0]['channel_id']),
				dbesc($data['secret']),
				dbesc($sender_hash)
			);
			if(! $z) {
				logger('mod_zot: auth_check: verification key not found.');
				json_return_and_die($ret);
			}
			$r = q("delete from verify where id = %d limit 1",
				intval($z[0]['id'])
			);

			logger('mod_zot: auth_check: success', LOGGER_DEBUG);
			$ret['result'] = true;
			json_return_and_die($ret);

		}
		json_return_and_die($ret);
	}


	// catchall
	json_return_and_die($ret);


}

