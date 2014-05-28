<?php

/**
 * @package BazaarVoice
 * @class   BazaarVoiceFeedBase
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    21 apr 2014
 * */
class BazaarVoiceFeedBase {

    protected $localFile                = null;
    protected $remoteFile               = null;
    protected static $ini               = null;
    protected static $dom               = null;
    protected static $feed              = null;
    protected static $cache             = array();
    protected static $progressDebugStep = 100;

    public function run() {
        try {
            $this->build();
            $this->save();
            $this->upload();
        } catch( Exception $e ) {
            self::debug( 'ERROR: ' . $e->getMessage() );
        }
    }

    protected static function fetchNodes( array $classes ) {
        $fetchParams = array(
            'Depth'            => false,
            'Limitation'       => array(),
            'LoadDataMap'      => false,
            'AsObject'         => false,
            'MainNodeOnly'     => true,
            'ClassFilterType'  => 'include',
            'ClassFilterArray' => $classes
        );

        return eZContentObjectTreeNode::subTreeByNodeID( $fetchParams, 1 );
    }

    protected static function getLanguageCode( $language ) {
        if( isset( self::$cache['langauge_codes'] ) === false ) {
            self::$cache['langauge_codes'] = array();
        }

        if( isset( self::$cache['langauge_codes'][$language] ) === false ) {
            $locale = eZLocale::instance( $language );
            self::$cache['langauge_codes'][$language] = $locale->attribute( 'http_locale_code' );
        }

        return self::$cache['langauge_codes'][$language];
    }

    protected static function getSiteAccessByLanguageCode( $languageCode ) {
        if( isset( self::$cache['siteaccesses'] ) === false ) {
            self::$cache['siteaccesses'] = array();
        }

        if( isset( self::$cache['siteaccesses'][$languageCode] ) === false ) {
            $availableSiteAccessList = eZINI::instance( 'site.ini' )->variable( 'SiteAccessSettings', 'AvailableSiteAccessList' );
            foreach( $availableSiteAccessList as $SA ) {
                $locale = eZLocale::instance( eZINI::getSiteAccessIni( $SA, 'site.ini' )->variable( 'RegionalSettings', 'ContentObjectLocale' ) );
                if( $locale->attribute( 'http_locale_code' ) == $languageCode ) {
                    self::$cache['siteaccesses'][$languageCode] = $SA;
                    break;
                }
            }
        }

        return isset( self::$cache['siteaccesses'][$languageCode] ) ? self::$cache['siteaccesses'][$languageCode] : null;
    }

    protected function debugProgress( $k, $count ) {
        $memoryUsage = number_format( memory_get_usage( true ) / ( 1024 * 1024 ), 2 );
        $message     = '[' . date( 'c' ) . '] ' . number_format( $k / $count * 100, 2 )
            . '% (' . $k . '/' . $count . '), Memory usage: ' . $memoryUsage . ' Mb';
        self::debug( $message );
    }

    protected static function debug( $message ) {
        eZCLI::instance()->output( $message );
    }

    protected function save() {
        if( @self::$dom->save( $this->localFile ) === false ) {
            throw new Exception( 'Unable to write feed content into "' . $this->localFile . '"' );
        }

        self::debug( 'Feed is stored in "' . $this->localFile . '" local file' );
        return true;
    }

    protected function upload() {
        $conn = ftp_connect( self::$ini->variable( 'FTP', 'Server' ) );
        if( ftp_login( $conn, self::$ini->variable( 'FTP', 'Username' ), self::$ini->variable( 'FTP', 'Password' ) ) === false ) {
            throw new Exception( 'Unable to connect to FTP server' );
        }

        $this->remoteFile = str_replace( 'UNIQUE_ID', date( 'dMY_His' ), $this->remoteFile );
        ftp_chdir( $conn, dirname( $this->remoteFile ) );
        if( ftp_put( $conn, basename( $this->remoteFile ), $this->localFile, FTP_BINARY ) === false ) {
            throw new Exception( 'Unable to upload feed to the FTP server' );
        }

        self::debug( 'Remote feed is uploaded to "' . $this->remoteFile . '"' );
        return true;
    }

    public static function htmlentities( $string ) {
        $string = htmlentities( $string, ENT_COMPAT, 'UTF-8' );

        $replaceMnemonics = array(
            '&euml;'   => '&#235;',
            '&pound;'  => '&#163;',
            '&oslash;' => '&#248;',
            '&rsquo;'  => '&#8217;',
            '&lsquo;'  => '&#8216;',
            '&trade;'  => '&#8482;'
        );
        foreach( $replaceMnemonics as $search => $replaceWith ) {
            $string = str_replace( $search, $replaceWith, $string );
        }

        return $string;
    }

}
