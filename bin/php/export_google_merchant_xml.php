<?php

require 'autoload.php';

$cli = eZCLI::instance();

$scriptSettings                   = array();
$scriptSettings['description']    = 'Export Google Merchant XML Product Feed';
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

$file = $options['file'] !== null ? $options['file'] : 'var/cache/google_merchant_export.xml';

$ini           = eZINI::instance();
$userCreatorID = $ini->variable( 'UserSettings', 'UserCreatorID' );
$user          = eZUser::fetch( $userCreatorID );
if( ( $user instanceof eZUser ) === false ) {
    $cli->error( 'Cannot get user object by userID = "' . $userCreatorID . '". ( See site.ini [UserSettings].UserCreatorID )' );
    $script->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, $userCreatorID );

$cli->output( '[' . date( 'c' ) . '] Exporting products...' );

$bvIni         = eZINI::instance( 'bazaar_voice.ini' );
$shopIni       = eZINI::instance('shop.ini' );

$brandName = $bvIni->variable('ProductsFeed', 'BrandName');
$currency = $shopIni->variable('CurrencySettings', 'PreferredCurrency');
$locale = $ini->variable('RegionalSettings', 'Locale');

$reviewsClass  = $bvIni->variable( 'ReviewsImport', 'ReviewClassIdentifier' );
$productsClass = $bvIni->variable( 'ProductsFeed', 'ProductClasses' );

$thisPriceGroup = $shopIni->variable('PriceSettings', 'PriceGroup');

$feedName = $ini->variable('SiteSettings', 'SiteName') . ' ' . $ini->variable('RegionalSettings', 'Locale');
$siteAccessUrl = $ini->variable('SiteSettings', 'SiteURL');
if (stripos($siteAccessUrl, '//') === false) {
    $siteAccessUrl = "https://$siteAccessUrl";
}

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

define('ATOM_NS', 'http://www.w3.org/2005/Atom');
define('MERCHANT_NS', 'http://base.google.com/ns/1.0');
define('MAX_PRODUCTS', 99999999);

$count = 0;

$doc               = new DOMDocument( '1.0', 'UTF-8' );
$doc->formatOutput = true;

$root = $doc->createElementNS( ATOM_NS,'feed');
$doc->appendChild($root);
$root->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:g', MERCHANT_NS);

// headers
$root->appendChild( $doc->createElementNS(ATOM_NS, 'title', htmlentities($feedName)));
$root->appendChild( $doc->createElementNS(ATOM_NS, 'updated', date( 'c' )));

$containerPath = $bvIni->variable( 'ProductsFeed', 'ProductContainerPath' );
$parentNode    = eZContentObjectTreeNode::fetchByURLPath( $containerPath, false );
$parentNodeID  = is_array( $parentNode ) ? $parentNode['node_id'] : 1;
$products      = eZContentObjectTreeNode::subTreeByNodeID( $productsFetchParams, $parentNodeID );
foreach( $products as $product ) {

    $productObject = eZContentObject::fetch( $product['contentobject_id'] );
    if( $productObject instanceof eZContentObject === false ) {
        continue;
    }

    $dataMap = $productObject->dataMap();

    $priceVariants = $dataMap['sku_prices']->content();
    $availableVariants = array();

    // filter the variants down to the ones available in this price group
    foreach ($priceVariants['main']['result'] as $priceVariantData) {
        if ($priceVariantData['Region'] == $thisPriceGroup) {
            $availableVariants[$priceVariantData['LongCode']] = $priceVariantData;
        }
    }

    // update each variant with price, stock, colour information
    $variations = $dataMap['variations']->content();
    foreach ($variations['main']['result'] as $variationData) {
        $longCode = $variationData['LongCode'];
        if (array_key_exists($longCode, $availableVariants)) {
            $extraInformation = array(
                'SiteAccessURL' => $siteAccessUrl,
                'BrandName' => $brandName,
                'Currency' => $currency,
                'Locale' => $locale
                );

            $availableVariants[$longCode] = array_merge($availableVariants[$longCode], $variationData, $extraInformation);
        }
    }

    // update with colour image map info
    $colourImageMap = $dataMap['colour_image_map']->content();
    foreach ($colourImageMap['main']['result'] as $colourImageMapData) {
        $colour = $colourImageMapData['Colour'];

        foreach ($availableVariants as $longCode => $variant) {

            if ($variant['Colour'] == $colour) {
                $availableVariants[$longCode] = array_merge($availableVariants[$longCode], $colourImageMapData);
            }
        }
    }

    foreach ($availableVariants as $variant) {
        appendVariantEntryToFeed($productObject, $variant, $doc, $root);
    }

    $count ++;

    if ($count >= MAX_PRODUCTS) {
        break;
    }

    if ($count % 10 == 0) {
        $cli->output("$count products exported");
    }
}

$doc->save( $file );

$cli->output("Done. $count products exported.");

$script->shutdown( 0 );

/**
 * Adds a variant entry to the output XML
 *
 * @param $productObject eZContentObject
 * @param $variant array union of the values in dataMap['sku_prices'] and dataMap['variations']
 * @param $doc XML doc to append to
 */
function appendVariantEntryToFeed($productObject, $variant, $doc, $xmlParentElement) {
    global $cli;

    $rootProductSku = getProductSKU($productObject);
    $longCode = $variant['LongCode'];

    $dataMap = $productObject->dataMap();

    $colour = $variant['Colour'];
    $variantName = htmlentities($productObject->name() . ' - ' . $colour);

    if (!array_key_exists('Imageid', $variant)) {
        $cli->output("No image for $variantName. Skipping. " . $productObject->mainNode()->urlAlias());
        return;
    }

    // ensure this prouct is not hidden or disabled
    if ($dataMap['disable_product']->content()) {
        $cli->output("Product $variantName is flagged as disabled (disabled_product == true). Skipping.");
        return;
    }

    if ($dataMap['hide_product']->content()) {
        $cli->output("Product $variantName is flagged as hidden (hidden_product == true). Skipping.");
        return;
    }

    if ($variant['Hidden']) {
        $cli->output("Product $variantName is flagged as hidden (variants.hidden == true for SKU). Skipping.");
        return;
    }

    // row is elegible to be written
    $entry = $doc->createElementNS(ATOM_NS, "entry");
    $xmlParentElement->appendChild($entry);

    // basic product info
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:id", $longCode));
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:title", $variantName));

    // convert description to plain text
    $converter = new eZXHTMLXMLOutput( $dataMap['short_description']->content()->XMLData, false, $dataMap['description']);
    $description = htmlentities(strip_tags($converter->outputText()), true);
    $description = str_replace("\n", " ", $description); // flatten to one line
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:description", $description));

    $url = $productObject->mainNode()->urlAlias();
    eZURI::transformURI( $url, false, 'relative' );
    $url = $variant['SiteAccessURL'] . $url;
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:link", $url));

    $variantImageIds = explode(',', $variant['Imageid']);
    $primaryImageId = $variantImageIds[0];
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:image_link", getVariantImageUrl($primaryImageId)));

    // stock levels
    $stockLevel = $variant['InStock'];
    $availablility = $stockLevel > 0 ? "in stock" : "out of stock";
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:availability", $availablility));
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:price", $variant['Price'] . " " . $variant['Currency']));

    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:condition", "new"));

    // variants
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:item_group_id", $rootProductSku));
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:color", htmlentities(ucwords($variant['Colour']))));

    // branding and identification
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:brand", $variant['BrandName']));
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:mpn", $longCode));

    $categoryUrl = $productObject->mainNode()->urlAlias();
    $category = implode(' > ', explode('/', $categoryUrl));
    $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:product_type", $category));

    // sale price
    if ($variant['OverridePrice'] == 1) {
        $entry->appendChild($doc->createElementNS(MERCHANT_NS, "g:sale_price", $variant['Override'] . ' ' . $variant['Currency']));
    }

    // not handled: is_bundle, shipping, tax
}

function getProductSKU( eZContentObject $object ) {
    $dataMap    = $object->attribute( 'data_map' );
    $externalID = $dataMap['product_id']->attribute( 'content' );
    $version    = trim( $dataMap['version']->attribute( 'content' ) );
    if( strlen( $version ) > 0 ) {
        $externalID .= '_' . $version;
    }
    $externalID = str_replace( '/', '|', $externalID );
    $externalID = strtoupper( $externalID );

    return htmlentities( $externalID );
}


function getVariantImageUrl($imageObjectId) {
    $image = eZContentObject::fetch( $imageObjectId );
    if( $image instanceof eZContentObject && $image->attribute( 'class_identifier' ) === 'image' ) {

        $imageDataMap = $image->attribute( 'data_map' );
        $aliasHandler = $imageDataMap['image']->attribute( 'content' );

        $alias = $aliasHandler->attribute('original');

        if( (bool) $alias['is_valid'] ) {
            $url = $alias['url'];

            eZURI::transformURI( $url, false, 'full' );
            return $url;
        }
    }

    return false;
}

