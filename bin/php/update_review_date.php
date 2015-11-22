<?php

/**
 * @package BazaarVoice
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    20 May 2014
 * */
require 'autoload.php';

$cli = eZCLI::instance();

$scriptSettings                   = array();
$scriptSettings['description']    = 'Update date value to published date, if empty';
$scriptSettings['use-session']    = true;
$scriptSettings['use-modules']    = true;
$scriptSettings['use-extensions'] = true;

$script = eZScript::instance( $scriptSettings );
$script->startup();
$script->getOptions();
$script->initialize();

$ini           = eZINI::instance();
$userCreatorID = $ini->variable( 'UserSettings', 'UserCreatorID' );
$user          = eZUser::fetch( $userCreatorID );
if( ( $user instanceof eZUser ) === false ) {
    $cli->error( 'Cannot get user object by userID = "' . $userCreatorID . '". ( See site.ini [UserSettings].UserCreatorID )' );
    $script->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, $userCreatorID );

$cli->output("Fetching reviews");

$params = array(
 'Depth' => false,
 'ClassFilterType' => 'include',
 'ClassFilterArray' => array('shop_product_review')
);

//Fetch all reviews
$reviews = eZContentObjectTreeNode::subTreeByNodeID( $params, 1 );
$cli->output( "Total reviews fetched - " . count($reviews) );

if ($reviews) {
    $counter = 0;
    foreach($reviews as $reviewNode) {
        
	$object = $reviewNode->Object();
	$dataMap = $reviewNode->DataMap();
	$reviewDateAttribute = $dataMap['date'];

        //$cli-output( "Date - " . $dataMap['date']->DataInt . " | Published - " . $object->attribute("published") );
	
	if($dataMap['date']->DataInt == 0) {
	    $cli->output("[Node id - " . $reviewNode->attribute('node_id') . " | Object id - " . $object->attribute('id') . " :: Updating review date to published date");
	    $reviewDateAttribute->setAttribute( 'data_int', $object->attribute("published") );
	    $reviewDateAttribute->sync();
	    $counter = $counter + 1;
        }		
    }
    $cli->output( "Counter :: [Fetched - " . count($reviews) . " | Updated - " . $counter . "]" );
}



$script->shutdown( 0 );
