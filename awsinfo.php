#!/usr/bin/php -q
<?php

/**
 *  @File
 *  This script gathers information about instances running in Amazon's
 *  EC2 platform.
 *
 *  We offer no warantee or guarantee - use this code at your own risk!
 *  All code is Copyright (C) 2011, Applied Trust Engineering, Inc.
 *
 */

/**
 *  This script expects an API key for Amazon's AWS EC2 service. It gets a list
 *  of all snapshots associated with the account (owner = self) and then
 *  analyzes the resulting list and prints out a details summary.
 *
 *  Written by Chris McDermott, AppliedTrust, chris@appliedtrust.com
 *    v1.1 8/19/2011
 *
 *  @param K
 *    Specify the Amazon EC2 API key.
 *  @param S
 *    Specify the Amazon EC2 API secret key. 
 *  @param f
 *    Specify a filter to use - either an IP address, an instance-id, or a hostname.
 *
 *  @return
 *    Returns 1 if there were errors, otherwise 0.
 */

putenv('TZ=America/Denver');
error_reporting(-1);

$options = getopt("S:K:f:c:l");

if (isset($options['l'])) {
  list_clients();
  exit;
}

if (!isset($options['c'])) {
  logthis('ERROR', "You must specify a valid client with the [-c] flag! Use the [-l] flag to see a list of clients.");
  exit;
}

$ec2options = read_client_config($options['c']);

// This file is the Amazon AWS PHP SDK, available from
// https://aws.amazon.com/sdkforphp/
require_once '/usr/local/aws-php-sdk/sdk.class.php';

if (isset($options['f'])) {
  if (preg_match("/^(i-\w+)$/", $options['f'], $matches)) {
    $filter = array(
      'Filter.1.Name' => 'instance-id',
      'Filter.1.Value' => $options['f'],
    );
  } elseif (preg_match("/^\d+\.\d+\.\d+\.\d+$/", $options['f'], $matches)) {
    $filter = array(
      'Filter.1.Name' => 'ip-address',
      'Filter.1.Value' => $options['f'],
    );
  } else {
    $filter = array(
      'Filter.1.Name' => 'tag-value',
      'Filter.1.Value' => $options['f'],
    );
  }
  ec2_instance_detail($ec2options, $filter);
} else {
  ec2_client_summary($ec2options);
}

/***********************************************************************************************/

function usage() {
  echo "Boo!\n";
  exit(2);
}

function logthis($type, $message) {
  echo "$type: $message\n";
}

function ec2_client_summary($ec2options) {
  $ec2 = new AmazonEC2($ec2options);
  $response = $ec2->describe_instances();
  if($response->status != '200') {
    logthis('ERROR', 'Non 200 response to ec2->describe_instances: ' . $response->body->Errors->Error->Message);
    exit;
  }
  echo "\nList of EC2 instances:\n";
  foreach($response->body->reservationSet->item as $item) {
    $id = $item->instancesSet->item->instanceId;
    $type = $item->instancesSet->item->instanceType;
    $name = $item->instancesSet->item->tagSet->item->value;
    $state = $item->instancesSet->item->instanceState->name;
    if ($state == "running") {
      $ip = $item->instancesSet->item->ipAddress;
    } else {
      $ip = "\t\t";
    }
    echo " * $id $type ($state) | $ip\t| $name\n";
  }
  echo "\n";
}

function ec2_instance_detail($ec2options, $filter) {
  $ec2 = new AmazonEC2($ec2options);
  $response = $ec2->describe_instances($filter);
  if($response->status != '200') {
    logthis('ERROR', 'Non 200 response to ec2->describe_instances: ' . $response->body->Errors->Error->Message);
    exit;
  }
  if (!isset($response->body->reservationSet->item)) {
    logthis("INFO", "Filter did not match any results");
    exit;
  }
  $id = $response->body->reservationSet->item->instancesSet->item->instanceId;
  $name = $response->body->reservationSet->item->instancesSet->item->tagSet->item->value;
  $type = $response->body->reservationSet->item->instancesSet->item->instanceType;
  $ami = $response->body->reservationSet->item->instancesSet->item->imageId;
  $az = $response->body->reservationSet->item->instancesSet->item->placement->availabilityZone;
  $security_groups = "";
  foreach($response->body->reservationSet->item->instancesSet->item->groupSet->item as $group) {
    $security_groups .= $group->groupName . ", ";
  }
  $block_devices = array();
  foreach($response->body->reservationSet->item->instancesSet->item->blockDeviceMapping->item as $device) {
    $volumeId = $device->ebs->volumeId;
    $deviceName = $device->deviceName;
    $volume_response = $ec2->describe_volumes(array('VolumeId' => $volumeId));
    if ($volume_response->status != '200') {
      logthis('ERROR', 'Non 200 response to ec2->describe_volumes: ' . $response->body->Errors->Error->Message);
      $description = "";
    } else {
      if (!isset($volume_response->body->volumeSet->item->tagSet->item->value)) {
        $description = "";
      } else {
        $description = $volume_response->body->volumeSet->item->tagSet->item->value;
      }
    }
    $options = array(
      'Filter.1.Name' => 'volume-id',
      'Filter.1.Value.1' => $volumeId,
    );
    $snapshots_response = $ec2->describe_snapshots($options);
    if ($snapshots_response->status != '200') {
      logthis('ERROR', 'Non 200 response to ec2->describe_snapshots: ' . $response->body->Errors->Error->Message);
      $numsnapshots = "Could not determine snapshot data.";
    } else {
      if (!isset($snapshots_response->body->snapshotSet->item)) {
        $numsnapshots = "Could not determine snapshot data.";
      } else {
        $numsnapshots = count($snapshots_response->body->snapshotSet->item);
      }
    }
    array_push($block_devices, "$volumeId - $deviceName ($description) ($numsnapshots snapshots)");
  }
  $state = $response->body->reservationSet->item->instancesSet->item->instanceState->name;
  if ($state == "running") {
    $ip = $response->body->reservationSet->item->instancesSet->item->ipAddress;
  } else {
    $ip = "(none)\t";
  }
  $reversedns = exec("/usr/bin/dig +short -x $ip >&1 /dev/null");
  if ($reversedns == "") {
    $reversedns = "No IP, so no reverse DNS";
  }
  echo "\n";
  echo "Instance $id\n";
  echo "  IP:\t\t" . $ip . "\t\t\t";
  echo "  Name:\t\t" . $name . "\n";
  echo "  State:\t" . $state . "\t\t\t\t";
  echo "  Type:\t\t" . $type . "\n";
  echo "  AMI:\t\t" . $ami . "\t\t\t";
  echo "  AZ:\t\t" . $az . "\n";
  echo "  Groups:\t" . $security_groups . "\n";
  echo "  Reverse DNS:\t" . $reversedns . "\n";
  echo "  Disks:\n";
  foreach($block_devices as $volume) {
    echo "\t$volume\n";
  }
  echo "\n";
}

function list_clients() {
  echo "\n";
  echo "The following is a list of all supported clients:\n";
  if ($handle = opendir('/home/aws/envs')) {
    while (false !== ($entry = readdir($handle))) {
      if ($entry != "." && $entry != "..") {
        echo "  * $entry\n";
      }
    }
  echo "\n";
  closedir($handle);
  }
}

function read_client_config($client) {
  $options = array();
  $access_key;
  $secret_key;
  if ($handle = fopen("/home/aws/envs/$client", 'r')) {
    while (!feof($handle)) {
      $entry = fgets($handle);
      if (preg_match("/^export EC2_ACCESS_KEY=\"??(.*)\"??$/U", $entry, $matches)) {
        $access_key = $matches[1];
      } elseif (preg_match("/^export EC2_SECRET_ACCESS_KEY=\"??(.*)\"??$/U", $entry, $matches)) {
        $secret_key = $matches[1];
      }
    }
  } else {
    logthis("ERROR", "Could not open $client config file - /home/aws/envs/$client!");
    exit(2);
  }
  if (isset($access_key) && isset($secret_key)) {
    $options = array(
      'key' => $access_key,
      'secret' => $secret_key,
    );
  } else {
    logthis("ERROR", "Could not parse access key and secret access key from client config file!");
    exit(2);
  }
  fclose($handle);
  return $options;
}

?>
