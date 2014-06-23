<?php

/**
 * @package BazaarVoice
 * @class   BazaarVoiceFeedProducts
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    21 apr 2014
 * */
class BazaarVoiceFeedProducts extends BazaarVoiceFeedBase {

    public function __construct() {
        self::$ini = eZINI::instance( 'bazaar_voice.ini' );

        self::$dom = new DOMDocument( '1.0', 'UTF-8' );
        self::$dom->formatOutput = true;

        self::$feed = self::$dom->createElement( 'Feed' );
        self::$feed->setAttribute( 'extractDate', date( 'c' ) );
        self::$feed->setAttribute( 'incremental', 'false' );
        self::$feed->setAttribute( 'name', self::$ini->variable( 'ProductsFeed', 'Name' ) );
        self::$feed->setAttribute( 'xmlns', self::$ini->variable( 'ProductsFeed', 'XMLNS' ) );
        self::$dom->appendChild( self::$feed );

        $this->localFile  = self::$ini->variable( 'ProductsFeed', 'LocalFile' );
        $this->remoteFile = self::$ini->variable( 'ProductsFeed', 'RemoteFile' );
    }

    protected function build() {
        $this->processBrands();
        $this->processCateogires();
        $this->processProducts();

        self::$dom->save( 'var/cache/p.xml' );
    }

    protected function processBrands() {
        self::debug( 'Processing brands...' );

        $container = self::$dom->createElement( 'Brands' );
        self::$feed->appendChild( $container );

        $item = self::$dom->createElement( 'Brand' );
        $container->appendChild( $item );

        $item->appendChild( self::$dom->createElement( 'Name', self::htmlentities( self::$ini->variable( 'ProductsFeed', 'BrandName' ) ) ) );
        $item->appendChild( self::$dom->createElement( 'ExternalId', self::$ini->variable( 'ProductsFeed', 'BrandExternalID' ) ) );
    }

    protected function processCateogires() {
        $params = array(
            'container_tag'             => 'Categories',
            'item_tag'                  => 'Category',
            'content_class_identifiers' => $this->getContentClassIdentifiers( 'Category' ),
            'extract_params_callback'   => $this->getExtractParamsCallback( 'Category' ),
        );

        $this->processObjects( $params );
    }

    protected function processProducts() {
        $params = array(
            'container_tag'             => 'Products',
            'item_tag'                  => 'Product',
            'content_class_identifiers' => $this->getContentClassIdentifiers( 'Product' ),
            'extract_params_callback'   => $this->getExtractParamsCallback( 'Product' ),
        );

        $this->processObjects( $params );
    }

    protected function getContentClassIdentifiers( $type ) {
        $classIdentifiers = explode( ',', self::$ini->variable( 'ProductsFeed', $type . 'Classes' ) );
        foreach( $classIdentifiers as $k => $classIdentifier ) {
            $classIdentifiers[$k] = trim( $classIdentifier );
        }

        return $classIdentifiers;
    }

    protected function getExtractParamsCallback( $type ) {
        return explode( '::', self::$ini->variable( 'ProductsFeed', $type . 'Callback' ) );
    }

    protected function processObjects( $params ) {
        self::debug( 'Processing ' . $params['container_tag'] . '...' );

        $nodes = self::fetchNodes( $params['content_class_identifiers'] );
        $count = count( $nodes );

        $container = self::$dom->createElement( $params['container_tag'] );
        self::$feed->appendChild( $container );

        $k = 1;
        foreach( $nodes as $node ) {
            $object = eZContentObject::fetch( $node['contentobject_id'] );
            if( $object instanceof eZContentObject === false ) {
                continue;
            }

            if( is_callable( $params['extract_params_callback'] ) ) {
                try {
                    $attributes = call_user_func( $params['extract_params_callback'], $object );
                } catch( Exception $e ) {
                    continue;
                }

                $item = self::$dom->createElement( $params['item_tag'] );
                $container->appendChild( $item );

                foreach( $attributes as $attr ) {
                    if( $attr instanceof DOMNode === false ) {
                        continue;
                    }

                    $item->appendChild( $attr );
                }
            }

            eZContentObject::clearCache( $object->attribute( 'id' ) );
            $object->resetDataMap();

            if( $k % self::$progressDebugStep === 0 || $k === 1 || $k === $count ) {
                self::debugProgress( $k, $count );
            }
            $k++;
        }
    }

    protected static function getCategoryAttributes( eZContentObject $object ) {
        $attributes = array(
            self::$dom->createElement( 'ExternalId', $object->attribute( 'id' ) )
        );

        $translatableAttributes = array(
            'Names'            => array(),
            'CategoryPageUrls' => array(),
            'ImageUrls'        => array()
        );
        $translations           = self::fetchTranslatedDataMaps( $object );
        foreach( $translations as $languageCode => $dataMap ) {
            $translatableAttributes['CategoryPageUrls'][$languageCode] = self::getTranslatedURL( $languageCode, $object->attribute( 'main_node' ) );
            $translatableAttributes['Names'][$languageCode]            = $dataMap['name']->attribute( 'content' );

            $image = $dataMap['image']->attribute( 'content' );
            if( $image instanceof eZContentObject && $image->attribute( 'class_identifier' ) === 'image' ) {
                $imageDataMap = $image->attribute( 'data_map' );
                $alias        = $imageDataMap['image']->attribute( 'content' )->attribute( 'original' );
                if( (bool) $alias['is_valid'] ) {
                    $url = $alias['url'];

                    $translatableAttributes['ImageUrls'][$languageCode] = self::getStaticTranslatedURL( $languageCode, $url );
                }
            }
        }

        if( count( $translatableAttributes['ImageUrls'] ) === 0 ) {
            unset( $translatableAttributes['ImageUrls'] );
        }

        foreach( $translatableAttributes as $attr => $values ) {
            $container = self::$dom->createElement( $attr );

            foreach( $values as $languageCode => $value ) {
                $element = self::$dom->createElement( substr( $attr, 0, -1 ), self::htmlentities( $value ) );
                $element->setAttribute( 'locale', str_replace( '-', '_', $languageCode ) );
                $container->appendChild( $element );
            }

            $attributes[] = $container;
        }

        return $attributes;
    }

    protected static function getProductAttributes( eZContentObject $object ) {
        $attributes = array(
            self::getProductID( $object ),
            self::getModelNumbers( $object ),
            self::getProductBrandID(),
            self::getProductCategoryID( $object )
        );

        $translatableAttributes = array(
            'Descriptions'    => array(),
            'ProductPageUrls' => array(),
            'ImageUrls'       => array()
        );
        $translations           = self::fetchTranslatedDataMaps( $object );
        foreach( $translations as $languageCode => $dataMap ) {
            $translatableAttributes['ProductPageUrls'][$languageCode] = self::getTranslatedURL( $languageCode, $object->attribute( 'main_node' ) );
            $translatableAttributes['Descriptions'][$languageCode]    = strip_tags( @$dataMap['short_description']->attribute( 'content' )->attribute( 'output' )->attribute( 'output_text' ) );

            $images = $dataMap['images']->attribute( 'content' );
            if( count( $images['relation_list'] ) > 0 ) {
                $image = eZContentObject::fetch( $images['relation_list'][0]['contentobject_id'] );
                if( $image instanceof eZContentObject && $image->attribute( 'class_identifier' ) === 'image' ) {
                    $imageDataMap = $image->attribute( 'data_map' );
                    $alias        = $imageDataMap['image']->attribute( 'content' )->attribute( 'original' );
                    if( (bool) $alias['is_valid'] ) {
                        $url = $alias['url'];

                        $translatableAttributes['ImageUrls'][$languageCode] = self::getStaticTranslatedURL( $languageCode, $url );
                    }
                }
            }
        }

        if( count( $translatableAttributes['ImageUrls'] ) === 0 ) {
            unset( $translatableAttributes['ImageUrls'] );
        }

        // We are using 1 SA to handle names
        $nameDataMap = BazaarVoiceFetchFunctions::fetchTranslatedObjectDataMap( $object->attribute( 'id' ), self::$ini->variable( 'ProductsFeed', 'NamesSA' ) );
        if( is_array( $nameDataMap ) === false || isset( $nameDataMap['data_map'] ) === false ) {
            throw new Exception( 'Name datamap can not be fetched' );
        }
        $translatableAttributes['Names'] = array(
            self::getLanguageCode( $nameDataMap['language'] ) => $nameDataMap['data_map']['name']->attribute( 'content' )
        );

        foreach( $translatableAttributes as $attr => $values ) {
            $container = self::$dom->createElement( $attr );

            foreach( $values as $languageCode => $value ) {
                $value = self::htmlentities( $value );

                $element = self::$dom->createElement( substr( $attr, 0, -1 ) );
                if( $attr == 'Descriptions' ) {
                    $element->appendChild( self::$dom->createCDATASection( $value ) );
                } else {
                    $element->nodeValue = $value;
                }
                $element->setAttribute( 'locale', str_replace( '-', '_', $languageCode ) );
                $container->appendChild( $element );
            }

            $attributes[] = $container;
        }

        return $attributes;
    }

    protected static function fetchTranslatedDataMaps( eZContentObject $object ) {
        $return       = array();
        $version      = $object->attribute( 'current' );
        $translations = $version->translations( false );

        foreach( $translations as $translation ) {
            $dataMap = array();
            $data    = $version->fetchAttributes(
                $version->attribute( 'version' ), $object->attribute( 'id' ), $translation
            );
            foreach( $data as $item ) {
                $dataMap[$item->contentClassAttributeIdentifier()] = $item;
            }

            // We are processing only eng translations
            $code = self::getLanguageCode( $translation );
            if( strpos( $code, 'en-' ) !== 0 ) {
                continue;
            }

            $return[$code] = $dataMap;
        }

        return $return;
    }

    protected static function getTranslatedURL( $languageCode, eZContentObjectTreeNode $node ) {
        $defaultHost = eZINI::instance()->variable( 'SiteSettings', 'SiteURL' );
        $sa          = self::getSiteAccessByLanguageCode( $languageCode );

        if( $sa !== null ) {
            // Set host of the siteaccess
            $host = eZINI::getSiteAccessIni( $sa, 'site.ini' )->variable( 'SiteSettings', 'SiteURL' );
            eZINI::instance()->setVariable( 'SiteSettings', 'SiteURL', $host );

            // Set SA path
            $tmp                         = explode( '/', $host );
            eZSys::instance()->IndexFile = '/' . end( $tmp );

            $node->CurrentLanguage = array_search( $languageCode, self::$cache['langauge_codes'] );
        }

        $url = $node->attribute( 'url_alias' );
        eZURI::transformURI( $url, false, 'full' );

        if( $sa !== null ) {
            eZINI::instance()->setVariable( 'SiteSettings', 'SiteURL', $defaultHost );
            eZSys::instance()->IndexFile = '/';
        }

        $url = str_replace( html_entity_decode( '&trade;' ), '%E2%84%A2', $url );

        return $url;
    }

    protected static function getStaticTranslatedURL( $languageCode, $url ) {
        $defaultHost = eZINI::instance()->variable( 'SiteSettings', 'SiteURL' );
        $sa          = self::getSiteAccessByLanguageCode( $languageCode );

        if( $sa !== null ) {
            // Set host of the siteaccess
            $host = eZINI::getSiteAccessIni( $sa, 'site.ini' )->variable( 'SiteSettings', 'SiteURL' );
            eZINI::instance()->setVariable( 'SiteSettings', 'SiteURL', $host );
        }

        eZURI::transformURI( $url, false, 'full' );

        if( $sa !== null ) {
            eZINI::instance()->setVariable( 'SiteSettings', 'SiteURL', $defaultHost );
        }

        return $url;
    }

    public static function getProductID( eZContentObject $object ) {
        $externalID = self::getProductSKU( $object );
        if( strlen( $externalID ) === 0 ) {
            throw new Exception( 'ExternalId can not be empty' );
        }

        return self::$dom->createElement( 'ExternalId', $externalID );
    }

    public static function getModelNumbers( eZContentObject $object ) {
        $container    = self::$dom->createElement( 'ModelNumbers' );
        $modeulNumber = self::$dom->createElement( 'ModelNumber', self::getProductSKU( $object ) );
        $container->appendChild( $modeulNumber );

        return $container;
    }

    public static function getProductSKU( eZContentObject $object ) {
        $dataMap    = $object->attribute( 'data_map' );
        $externalID = $dataMap['product_id']->attribute( 'content' );
        $version    = trim( $dataMap['version']->attribute( 'content' ) );
        if( strlen( $version ) > 0 ) {
            $externalID .= '_' . $version;
        }
        $externalID = str_replace( '/', '|', $externalID );
        $externalID = strtoupper( $externalID );

        return self::htmlentities( $externalID );
    }

    protected static function getProductBrandID() {
        $brandID = self::$ini->variable( 'ProductsFeed', 'BrandExternalID' );
        return self::$dom->createElement( 'BrandExternalId', $brandID );
    }

    protected static function getProductCategoryID( eZContentObject $object ) {
        $node = $object->attribute( 'main_node' );
        $k    = 0;

        while( $node->attribute( 'class_identifier' ) !== 'product_category' && $k < 20 ) {
            $k++;
            $node = $node->attribute( 'parent' );
        }

        return self::$dom->createElement( 'CategoryExternalId', $node->attribute( 'contentobject_id' ) );
    }

}
