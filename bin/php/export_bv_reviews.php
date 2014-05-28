<?php

/**
 * @package BazaarVoice
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    22 May 2014
 * */
require 'autoload.php';

$cli = eZCLI::instance();

$scriptSettings                   = array();
$scriptSettings['description']    = 'Export BV reviews';
$scriptSettings['use-session']    = true;
$scriptSettings['use-modules']    = true;
$scriptSettings['use-extensions'] = true;

$script  = eZScript::instance( $scriptSettings );
$script->startup();
$options = $script->getOptions(
    '[file:]', '', array(
    'file' => 'File in which export results will be saved'
    )
);
$script->initialize();

$file = $options['file'] !== null ? $options['file'] : 'var/cache/bv_reviews_export.xml';

$ini           = eZINI::instance();
$userCreatorID = $ini->variable( 'UserSettings', 'UserCreatorID' );
$user          = eZUser::fetch( $userCreatorID );
if( ( $user instanceof eZUser ) === false ) {
    $cli->error( 'Cannot get user object by userID = "' . $userCreatorID . '". ( See site.ini [UserSettings].UserCreatorID )' );
    $script->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, $userCreatorID );

$cli->output( '[' . date( 'c' ) . '] Exporting reviews...' );

$bvIni         = eZINI::instance( 'bazaar_voice.ini' );
$reviewsClass  = $bvIni->variable( 'ReviewsImport', 'ReviewClassIdentifier' );
$productsClass = $bvIni->variable( 'ProductsFeed', 'ProductClasses' );

$attrsMap            = array(
    'title'  => 'Title',
    'review' => 'ReviewText',
    'rating' => 'Rating',
    'email'  => 'UserEmailAddress'
);
$productsFetchParams = array(
    'Depth'            => false,
    'Limitation'       => array(),
    'LoadDataMap'      => false,
    'AsObject'         => false,
    'ClassFilterType'  => 'include',
    'ClassFilterArray' => array( $productsClass )
);
$reviewsFetchParams  = array(
    'Depth'            => false,
    'Limitation'       => array(),
    'LoadDataMap'      => false,
    'AsObject'         => false,
    'ClassFilterType'  => 'include',
    'ClassFilterArray' => array( $reviewsClass )
);

$doc               = new DOMDocument( '1.0', 'UTF-8' );
$doc->formatOutput = true;

$root = $doc->createElement( 'Feed' );
$root->setAttribute( 'xmlns', 'http://www.bazaarvoice.com/xs/PRR/StandardClientFeed/5.6' );
$root->setAttribute( 'name', $bvIni->variable( 'ProductsFeed', 'Name' ) );
$root->setAttribute( 'extractDate', date( 'c' ) );
$doc->appendChild( $root );

$counter  = 0;
$products = eZContentObjectTreeNode::subTreeByNodeID( $productsFetchParams, 1 );
foreach( $products as $product ) {
    $reviews = eZContentObjectTreeNode::subTreeByNodeID( $reviewsFetchParams, $product['node_id'] );
    if( count( $reviews ) === 0 ) {
        continue;
    }

    $pObject = eZContentObject::fetch( $product['contentobject_id'] );
    if( $pObject instanceof eZContentObject === false ) {
        continue;
    }

    $productSKU = BazaarVoiceFeedProducts::getProductSKU( $pObject );
    if( strlen( $productSKU ) === 0 ) {
        continue;
    }

    $productNode = $doc->createElement( 'Product' );
    $productNode->setAttribute( 'id', $productSKU );
    $productNode->appendChild( $doc->createElement( 'ExternalId', $productSKU ) );
    $reviewsNode = $doc->createElement( 'Reviews' );
    $productNode->appendChild( $reviewsNode );
    $root->appendChild( $productNode );

    foreach( $reviews as $review ) {
        $rObject = eZContentObject::fetch( $review['contentobject_id'] );
        if( $rObject instanceof eZContentObject === false ) {
            continue;
        }

        $dataMap = $rObject->attribute( 'data_map' );

        $reviewNode = $doc->createElement( 'Review' );
        $reviewNode->setAttribute( 'id', $rObject->attribute( 'remote_id' ) );
        $reviewNode->setAttribute( 'removed', 'false' );
        $reviewNode->appendChild( $doc->createElement( 'ModerationStatus', 'APPROVED' ) );

        $name            = iconv( 'UTF-8', 'ISO-8859-1', $dataMap['name']->attribute( 'content' ) );
        $displayName     = BazaarVoiceFeedBase::htmlentities( $name );
        $userProfileNode = $doc->createElement( 'UserProfileReference' );
        $userProfileNode->setAttribute( 'id', 1 );
        $userProfileNode->appendChild( $doc->createElement( 'DisplayName', $displayName ) );
        $userProfileNode->appendChild( $doc->createElement( 'Anonymous', 'false' ) );
        $userProfileNode->appendChild( $doc->createElement( 'HyperlinkingEnabled', 'false' ) );
        $reviewNode->appendChild( $userProfileNode );

        foreach( $attrsMap as $attr => $tag ) {
            $content = iconv( 'UTF-8', 'ISO-8859-1', $dataMap[$attr]->attribute( 'content' ) );
            $value   = BazaarVoiceFeedBase::htmlentities( $content );
            if( strlen( $value ) > 0 ) {
                $reviewNode->appendChild( $doc->createElement( $tag, $value ) );
            }
        }

        $reviewNode->appendChild( $doc->createElement( 'DisplayLocale', 'en_US' ) );
        $reviewNode->appendChild( $doc->createElement( 'SubmissionTime', date( 'c', $rObject->attribute( 'published' ) ) ) );

        $reviewsNode->appendChild( $reviewNode );

        eZContentObject::clearCache( $rObject->attribute( 'id' ) );
        $rObject->resetDataMap();

        $counter++;
    }

    eZContentObject::clearCache( $pObject->attribute( 'id' ) );
}

$doc->save( $file );
$cli->output( '[' . date( 'c' ) . '] ' . $counter . ' reviews were exported to "' . $file . '"' );

$script->shutdown( 0 );
