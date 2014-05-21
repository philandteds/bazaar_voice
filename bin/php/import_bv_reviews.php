<?php

/**
 * @package BazaarVoice
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    20 May 2014
 * */
require 'autoload.php';

$cli = eZCLI::instance();

$scriptSettings                   = array();
$scriptSettings['description']    = 'Import BV reviews';
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

// Connecting to FTP
$bvIni  = eZINI::instance( 'bazaar_voice.ini' );
$server = $bvIni->variable( 'FTP', 'Server' );
$conn   = ftp_connect( $server );
if( @ftp_login( $conn, $bvIni->variable( 'FTP', 'Username' ), $bvIni->variable( 'FTP', 'Password' ) ) === false ) {
    $cli->error( 'Unable to connect to "' . $server . '" FTP server' );
    $script->shutdown( 1 );
}

// Downloading archive
$remoteFile = $bvIni->variable( 'ReviewsImport', 'RemoteFile' );
$localFile  = $bvIni->variable( 'ReviewsImport', 'LocalFile' );
$cli->output( '[' . date( 'c' ) . '] Downloading "' . $remoteFile . '" from FTP ...' );
if( @ftp_get( $conn, $localFile, $remoteFile, FTP_BINARY ) === false ) {
    $cli->error( 'Download failed' );
    $script->shutdown( 1 );
}

// Extrating reviews feed from archive
$feedFile = str_replace( '.gz', '', $localFile );
$cli->output( '[' . date( 'c' ) . '] Extracting "' . $localFile . '" archive to "' . $feedFile . '"...' );

$h  = fopen( $feedFile, 'w' );
$zh = gzopen( $localFile, 'r' );
if( $zh === false ) {
    $cli->error( '"' . $localFile . '" is not valid archive' );
    $script->shutdown( 1 );
}
while( $line = gzgets( $zh, 1024 ) ) {
    fwrite( $h, $line );
}
gzclose( $zh );
fclose( $h );
$cli->output( '[' . date( 'c' ) . '] "' . $feedFile . '" is extracted' );
chmod( $feedFile, 0755 );

// Validate reviews feed
if( file_exists( $feedFile ) === false ) {
    $cli->error( 'Reviews feed file "' . $feedFile . '" does not exist or it is not readable' );
    $script->shutdown( 1 );
}
$dom = new DOMDocument;
if( $dom->loadXML( file_get_contents( $feedFile ) ) === false ) {
    $cli->error( 'Reviews feed file "' . $feedFile . '" is not valid XML file' );
    $script->shutdown( 1 );
}

// Processing reviews
$cli->output( '[' . date( 'c' ) . '] Processing reviews...' );

$containerName  = $bvIni->variable( 'ReviewsImport', 'ContainerName' );
$containerClass = $bvIni->variable( 'ReviewsImport', 'ContainerClassIdentifier' );
$reviewsClass   = $bvIni->variable( 'ReviewsImport', 'ReviewClassIdentifier' );
$attributesMap  = array(
    'title'                     => 'Title',
    'review'                    => 'ReviewText',
    'email'                     => 'UserEmailAddress',
    'name'                      => 'DisplayName',
    'display_name'              => 'DisplayName',
    'rating'                    => 'Rating',
    'recommended'               => 'Recommended',
    'positive_feedbacks_number' => 'NumPositiveFeedbacks',
    'negative_feedbacks_number' => 'NumNegativeFeedbacks',
    'create_date'               => 'SubmissionTime'
);

$k        = 1;
$products = $dom->getElementsByTagName( 'Product' );
$count    = (int) $products->length;
foreach( $products as $product ) {
    $cli->output( str_repeat( '-', 80 ) );
    $memoryUsage = number_format( memory_get_usage( true ) / ( 1024 * 1024 ), 2 );
    $message     = '[' . date( 'c' ) . '] ' . number_format( $k / $count * 100, 2 )
        . '% (' . $k . '/' . $count . '), Memory usage: ' . $memoryUsage . ' Mb';
    $cli->output( $message );
    $k++;

    if( $product->hasAttribute( 'id' ) === false ) {
        continue;
    }

    // extract product id and version
    $parts     = explode( '_', $product->getAttribute( 'id' ) );
    $productID = str_replace( '|', '/', $parts[0] );
    $version   = isset( $parts[1] ) ? $parts[1] : '';

    // fetch product node
    $pNode = ContentSyncImportHandlerXrowProduct::fetchNode( $productID . '|' . $version );
    if( $pNode instanceof eZContentObjectTreeNode === false ) {
        $message = '[' . date( 'c' ) . '] Unable to fetch "' . $product->getAttribute( 'id' ) . '" product';
        $cli->output( $message );
        continue;
    }

    $message = '[' . date( 'c' ) . '] Prcoessing reviews for "' . $pNode->attribute( 'name' )
        . '" (' . $pNode->attribute( 'url_alias' ) . ') product';
    $cli->output( $message );

    // extract reviews
    $reviews = $product->getElementsByTagName( 'Review' );
    if( (int) $reviews->length === 0 ) {
        continue;
    }

    // fetch reviews container
    $containerNodeID = null;
    $params          = array(
        'Depth'            => false,
        'ClassFilterType'  => 'include',
        'ClassFilterArray' => array( $containerClass ),
        'LoadDataMap'      => false,
        'AsObject'         => false,
        'Limitation'       => array(),
        'IgnoreVisibility' => true
    );
    $nodes           = eZContentObjectTreeNode::subTreeByNodeID( $params, $pNode->attribute( 'node_id' ) );
    if( count( $nodes ) > 0 ) {
        $containerNodeID = $nodes[0]['node_id'];
    }

    // create reviews cotnainer if it does not exist
    if( $containerNodeID === null ) {
        $params          = array(
            'creator_id'       => $ini->variable( 'UserSettings', 'UserCreatorID' ),
            'class_identifier' => $containerClass,
            'parent_node_id'   => $pNode->attribute( 'node_id' ),
            'attributes'       => array( 'name' => $containerName )
        );
        $object          = eZContentFunctions::createAndPublishObject( $params );
        $containerNodeID = $object->attribute( 'main_node_id' );
    }

    if( $containerNodeID === null ) {
        continue;
    }

    $createdReviews = 0;
    $skippedReviews = 0;
    foreach( $reviews as $review ) {
        if( $review->hasAttribute( 'id' ) === false ) {
            $skippedReviews++;
            continue;
        }

        // Check if current review is already imported
        $remoteID = 'BV_Review_' . $review->getAttribute( 'id' );
        if( eZContentObject::fetchByRemoteID( $remoteID ) instanceof eZContentObject ) {
            $skippedReviews++;
            continue;
        }

        // Extract review attributes
        $attributes = array();
        foreach( $attributesMap as $attr => $tag ) {
            $attrNodes = $review->getElementsByTagName( $tag );
            if( $attrNodes->length === 0 ) {
                continue;
            }
            $value = (string) $attrNodes->item( 0 )->nodeValue;

            if( $attr == 'recommended' ) {
                $value = $value === 'true';
            } elseif( $attr == 'create_date' ) {
                $value = strtotime( $value );
            }

            $attributes[$attr] = $value;
        }

        // Publish review
        $params = array(
            'remote_id'        => $remoteID,
            'class_identifier' => $reviewsClass,
            'parent_node_id'   => $containerNodeID,
            'attributes'       => $attributes
        );
        $object = eZContentFunctions::createAndPublishObject( $params );
        if( $object instanceof eZContentObject ) {
            $object->setAttribute( 'published', $attributes['create_date'] );
            $object->store();

            $createdReviews++;
        }
    }

    $message = '[' . date( 'c' ) . '] Created reviews: ' . $createdReviews . ', Skipped reviews: ' . $skippedReviews;
    $cli->output( $message );
}
$script->shutdown( 0 );
