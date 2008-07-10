#!/usr/bin/php
<?php

require 'autoload.php';

ignore_user_abort( true );

//ob_start();

set_time_limit( 0 );

//ini_set( "display_errors" , "0" );

error_reporting( E_ALL | E_NOTICE );

// Turn off session stuff, isn't needed for FTP operations.
$GLOBALS['eZSiteBasics']['session-required'] = false;

include_once( 'extension/ezftp/classes/ezftpserver.php' );

// Check for extension
require_once( 'kernel/common/ezincludefunctions.php' );
eZExtension::activateExtensions( 'default' );
// Extension check end

// Make sure site.ini and template.ini reloads its cache incase
// extensions override it
$ini =& eZINI::instance( 'site.ini' );
$ini->loadCache();

function eZFatalError()
{
    //eZDebug::setHandleType( eZDebug::HANDLE_NONE );
    print( "* Fatal error: eZ Publish did not finish its request. See logs for details.\n" );
}

function exitWithInternalError()
{
    print( "* Internal error: eZ Publish did not finish its request. See logs for details.\n" );
    eZExecution::cleanup();
    eZExecution::setCleanExit();
}

function eZUpdateDebugSettings()
{
    $ini = eZINI::instance();
    $debugSettings = array();
    $debugSettings['debug-enabled'] = $ini->variable( 'DebugSettings', 'DebugOutput' ) == 'enabled';
    $debugSettings['debug-by-ip']   = false;
    eZDebug::updateSettings( $debugSettings );
}

function eZUpdateTextCodecSettings()
{
    $ini = eZINI::instance( 'i18n.ini' );

    list( $i18nSettings['internal-charset'], $i18nSettings['http-charset'], $i18nSettings['mbstring-extension'] ) =
        $ini->variableMulti( 'CharacterSettings', array( 'Charset', 'HTTPCharset', 'MBStringExtension' ), array( false, false, 'enabled' ) );

    //$i18nSettings['internal-charset'] = 'ASCII';

    eZTextCodec::updateSettings( $i18nSettings );
}

function eZDBCleanup()
{
    if ( class_exists( 'ezdb' )
         and eZDB::hasInstance() )
    {
        $db = eZDB::instance();
        $db->setIsSQLOutputEnabled( false );
    }
}

// Initialize text codec settings
eZUpdateTextCodecSettings();

eZExecution::addCleanupHandler( 'eZDBCleanup' );
eZExecution::addFatalErrorHandler( 'eZFatalError' );
eZDebug::setHandleType( eZDebug::HANDLE_FROM_PHP );
eZModule::setGlobalPathList( array( "kernel" ) );

//set the siteaccess to the default siteaccess
require_once( "access.php" );
require_once( "kernel/common/i18n.php" );

$defaultAccess = $ini->variable( 'SiteSettings', 'DefaultAccess' );
eZFTPServer::setCurrentSite( $defaultAccess );

$server = new eZFTPServer();

if ( !$server->run() )
{
    exitWithInternalError();
}    

eZExecution::cleanup();
eZExecution::setCleanExit();

?>