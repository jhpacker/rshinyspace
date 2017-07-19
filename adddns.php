<?php

include("awskeys.cfg");

$remote_hostname = gethostbyaddr($_SERVER['REMOTE_ADDR']);

if (!(preg_match('/^curl/', $_SERVER['HTTP_USER_AGENT']) && preg_match('/amazonaws.com$/', $remote_hostname))){
	print "sorry, this has to be run from your AWS instance: $hostname\n<br>";
	exit;
    }

$hostname = escapeshellcmd($_REQUEST['hostname']);
$ec2name = escapeshellcmd($_REQUEST['ec2name']);

// require this to be called from the host we are creating the cname for
if ($remote_hostname != $ec2name){
   print "sorry, you can't specify that hostname\n<br>";
   exit;
}

putenv("AWS_ACCESS_KEY_ID=$aws_key");
putenv("AWS_SECRET_ACCESS_KEY=$aws_secret");

$cli53 = shell_exec("/usr/local/bin/cli53 rrcreate --replace rshiny.space '$hostname 60 CNAME $ec2name.'");
print $cli53;

?>