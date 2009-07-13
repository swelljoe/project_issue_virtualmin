<?php
// $Id: bug.php $

/**
 * @file
 * Handles incoming requests to create tickets in project_issue.
 */

include_once './includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

# XXX Validate required parameters (subject, body, OS)
$subject = $_POST['subject'];
$body = preg_replace('/\r/', '', $_POST['body']); // Strip DOS CR
$os = $_POST['os'];
$version = $_POST['version'];
$private = $_POST['private'];
$validation = $_POST['validation'];
$configcheck = $_POST['configcheck'];
$collected = $_POST['collected'];

$remote_ip = $_SERVER['REMOTE_ADDR'];
$serial = $_POST['serial'];
$key = $_POST['key'];

$validation = $_POST['validation'];
$configcheck = $_POST['configcheck'];
$collected = $_POST['collected'];

// Lookup uid by serial and key; I think we should probably give up
// with no valid license and serial.
$license = db_fetch_object(db_query("SELECT * from {software_license} ".
         "WHERE serial_id = '%d' AND license_key = '%s'",
         $serial, $key));
$uid = $license->uid;
if ( empty($uid) ) {
   echo "Serial number and license key cannot be mapped to a valid user.<br>\n";
   echo "Your ticket should be filed directly at Virtualmin.com.\n";
   error_log("bug.php access by $remote_ip with $serial $key");
   exit;
}

// Always known
$tasktype = 'bug'; // Always a 'bug'?
$pid = '97'; // Always virtual-server, even if not.

$issue = new stdClass();
$issue->pid = '97'; // Virtualmin
$issue->category = 'bug';
$issue->component = 'Code';
$issue->priority = 2; // Normal
$issue->title = $subject;
$issue->body = $body;
$issue->uid = $uid;
$issue->type = 'project_issue';
$issue->sid = 1; // active
$issue->created = time();
$issue->field_operating_system[0]['value'] = 'Other'; //Body has full OS
$issue->changed = $issue->created;
$issue->format = 5; // Markdown, allows filtered HTML, as well.
$issue->comment = 2; // Allow comments, otherwise they're invisible!

if ($private) {
  $issue->private=1;
}

node_save($issue);
$nid=$issue->nid;

if ($validation || $configcheck || $collected) {
  // Process attachments
  $issue_dir = variable_get('project_directory_issues', 'issues');
  $dest = file_directory_path() .'/'. $issue_dir; // Drupal's standard files path
  if (! file_check_directory($dest) ) {
    echo "<br>Error processing attachments.  Event has been logged and an administrator notified.\n";
    error_log("Attachment directory $dest does not exist or is not writable.");
    exit;
  }

  foreach (array($validation, $configcheck, $collected) as $attachment) {
    if (empty($attachment)) { continue; }

    // Generate a randomish unique file name
    $file_id = $nid ."_". time() . posix_getpid() . (++$acount);
    // Save the file and put the path into $file
    $file = file_save_data($attachment, "$dest/$file_id", $replace = FILE_EXISTS_RENAME);

    // Make it a node in Drupal
    // Get the file size
    $details = stat($file);
    $filesize = $details['size'];
    $name = basename($file);

    // Build the file object
    $file_obj = new stdClass();
    $file_obj->filename = $name;
    $file_obj->filepath = $file;
    $file_obj->filemime = 'text/plain';
    $file_obj->filesize = $filesize;
    // You can change this to the UID you want
    $file_obj->uid = $uid;
    $file_obj->status = FILE_STATUS_TEMPORARY;
    $file_obj->timestamp = time();
    $file_obj->list = 1;
    $file_obj->new = true;

    // Save file to files table
    drupal_write_record('files', $file_obj);

    // change file status to permanent
    file_set_status($file_obj,1);

    // Attach the file object to your node  XXX create/attach to comment?
    $issue->files[$file_obj->fid] = $file_obj;      
  }
}
upload_save($issue);

// Mark it private.  It'd be nice if this could be wrapped in a transaction
// with above node_save.
//if ($private) {
  //db_query("UPDATE {node_access},{private} SET gid='$uid', realm='private_author', grant_view='1', grant_update='1', grant_delete='1', private='1' WHERE {node_access}.nid='$nid' AND {private}.nid={node_access}.nid");
  //db_query("UPDATE {private} SET private='1' WHERE nid='$nid')");
//}

// Now that everything is privatized and such, send notifications
project_mail_notify($nid);

print "OK $nid http://www.virtualmin.com/node/$nid\n";
