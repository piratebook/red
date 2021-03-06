<?php

function zperms_init(&$a) {

	require_once('include/zot.php');
	require_once('include/Contact.php');
	require_once('include/crypto.php');

	$ret = array('success' => false);

	$zguid   = ((x($_REQUEST,'guid'))        ? $_REQUEST['guid']    : '');
	$zaddr   = ((x($_REQUEST,'address'))     ? $_REQUEST['address'] : '');
	$ztarget = ((x($_REQUEST,'target'))      ? $_REQUEST['target']  : '');
	$zsig    = ((x($_REQUEST,'target_sig'))  ? $_REQUEST['target_sig']  : '');
		
	$r = null;

	if(strlen($zguid)) {
		$r = q("select * from channel where channel_guid = '%s' limit 1",
			dbesc($zguid)
		);
	}
	elseif(strlen($zaddr)) {
		$r = q("select * from channel where channel_address = '%s' limit 1",
			dbesc($zaddr)
		);
	}
	else {
		$ret['message'] = 'Invalid request';
		json_return_and_die($ret);
	}

	if(! ($r && count($r))) {
		$ret['message'] = 'Item not found.';
		json_return_and_die($ret);
	}
	$e = $r[0];

	$id = $e['channel_id'];
	$r = q("select contact.*, profile.* 
		from contact left join profile on contact.uid = profile.uid
		where contact.uid = %d && contact.self = 1 and profile.is_default = 1 limit 1",
		intval($id)
	);
	if($r && count($r)) {
		$profile = $r[0];
	}



	$ret['success'] = true;
	$ret['guid'] = $e['channel_guid'];
	$ret['guid_sig'] = base64url_encode(rsa_sign($e['channel_guid'],$e['channel_prvkey']));
	$ret['key']  = $e['channel_pubkey'];
	$ret['name'] = $e['channel_name'];
	$ret['address'] = $e['channel_address'];
	$ret['target'] = $ztarget;
	$ret['target_sig'] = $zsig;
	$ret['permissions'] =  map_perms($r[0],$ztarget,$zsig);

	$ret['profile'] = $profile;

	// array of (verified) hubs this channel uses

	$ret['hubs'] = array();
	$x = zot_get_hubloc(array($e['channel_guid']));
	if($x && count($x)) {
		foreach($x as $hub) {
			if(! ($hub['hubloc_flags'] & HUBLOC_FLAGS_UNVERIFIED)) {
				$ret['hubs'][] = array(
					'primary'  => (($hub['hubloc_flags'] & HUBLOC_FLAGS_PRIMARY) ? true : false),
					'url'      => $hub['hubloc_url'],
					/// hmmm we probably shouldn't sign somebody else's hub. FIXME
					'url_sig'  => base64url_encode(rsa_sign($hub['hubloc_url'],$e['channel_prvkey'])),
					'callback' => $hub['hubloc_callback'],
					'sitekey'  => $hub['hubloc_sitekey']
				);
			}
		}
	}

	json_return_and_die($ret);

}
