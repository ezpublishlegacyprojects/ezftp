<?php

include_once( 'extension/ezftp/classes/ezftpinout.php' );

class eZFTPInOut
{
    /*
    const SUCCESS = 1;
    const FAILED_NOT_FOUND = -1;
    const FAILED_FORBIDDEN = -2;
    const FAILED_INVALID_TYPE = -3;
    */
    
    const TYPE_DIRECTORY = 1;
    const TYPE_FILE = 2;

    /**
     * Constructor
     */
	public function eZFTPInOut( &$client )
    {
        $this->client = &$client;
        $this->folderClasses = null;
        $this->path = false;
        $this->reset();
	}
    
    /**
     * Reset internal variables
     */
    private function reset()
    {
        $this->nodePath = false;
        $this->site = false;
        //$this->virtualFolder = false;
        $this->node = null;
        $this->exists = false;
        $this->canRead = false;
        $this->canModify = false;
        $this->canCreate = false;
        $this->canDelete = false;
        $this->tmpFp = false;
        $this->tmpFilepath = false;
    }
    
    /**
     * Set the current path to work with,
     * and parse it
     */
    public function setPath( $path )
    {
    	$this->reset();
        
        $path = $this->decodePath( $path );
        
        $this->path = $path;
        
        // We're on the root dir
        if ( !$this->path || $this->path == '/')
        {
            $this->exists = true;
            $this->canRead = true;
            return;
        }
        
        $path = $this->splitFirstPathElement( $this->path, $site );
        
        if ( !$site )
        {
            return;
        }
        
        if ( !in_array( $site, $this->availableSites() ) )
        {
            return;
        }
        
        $this->site = $site;

        if ( !$this->userHasSiteAccess( $this->site ) )
        {
           $this->exists = true;
           return;
        }

        // We have reached the end of the path /siteacess/<content/media>
        if ( !$path )
        {
            $this->exists = true;
            $this->canRead = true;
            return;
        }
        
        eZFTPServer::setCurrentSite( $this->site );
        
        $path = $this->splitFirstPathElement( $path, $virtualFolder );
   
        if ( !in_array( $virtualFolder, $this->virtualFolderList() ) )
        {
            $this->exists = false;
            return;
        }
        
        //$this->virtualFolder = $virtualFolder;
        
        $nodePath = $this->internalNodePath( $virtualFolder, $path );
        
        $this->nodePath = $nodePath;

        $node = $this->fetchNodeByTranslation( $nodePath );
      
        if ( !$node )
        {
            $this->exists = false;
            return;
        }
        
        $this->node = $node;

        //TODO check if user has access to this node
//        if ( !$this->userHasAccess( $this->site ) )
//        {
//            return false;
//        }

        $this->exists = true;
        $this->canRead = true;
        $this->canModify = true;
        $this->canCreate = true;
        $this->canDelete = true;
        return;
    }

    /**
     * @return true if the working path exists
     */
    public function exists()
    {
    	return $this->exists;
    }

    /**
     * @return const the type of the working path
     */
    public function type()
    {
    	if ( $this->node )
        {
            if ( !$this->isFolder( $this->node ) )
            {
                return eZFTPInOut::TYPE_FILE;
            }
            else
            {
                return eZFTPInOut::TYPE_DIRECTORY;
            }
        }
        elseif ( $this->site )
        {
            return eZFTPInOut::TYPE_DIRECTORY;
        }
        else
        {
            return eZFTPInOut::TYPE_DIRECTORY;
        }
    }
    
    /**
     * @return boolean if user can read the working path
     */
    public function canRead()
    {
    	return $this->canRead;
    }
    
    /**
     * @return boolean if user can read the working path
     */
    public function canModify()
    {
        return $this->canModify;
    }

    /**
     * @return boolean if user can read the working path
     */
    public function canCreate()
    {
        return $this->canCreate;
    }

    /**
     * @return boolean if user can read the working path
     */
    public function canDelete()
    {
        return $this->canDelete;
    }

    /**
     * Return list of files in the working path
     * @return array
     */
    public function ls()
    {
        if ( $this->node )
        {
            if ( !$this->isFolder( $this->node ) )
            {
                return array();
            }
            else
            {
                return $this->folderLs( $this->node );
            }
        }
        elseif ( $this->site )
        {
            return $this->siteLs( $this->site );
        }
        else
        {
            return $this->rootLs();
        }
    }
    
    public function read()
    {
        $object = $this->node->attribute( 'object' );
        $upload = new eZContentUpload();
        $info = $upload->objectFileInfo( $object );
        $file = eZClusterFileHandler::instance( $info['filepath'] );

        if ( !$file || !$file->exists() )
        {
            return '';
        }
        $data = $file->fetchContents();
        return $data;
    }
    
    function openTemporaryFile( $append = false )
    {
        $type = ($append) ? "a" : "w";
      
 //       if (($type == "a" || $type == "r") && ! $this->exists($filename)) return false;
 
        $dir = eZFTPInOut::tempDirectory() . '/' . md5( microtime() . '-' . $this->nodePath );
        $this->tmpFilepath = $dir . '/' . basename( $this->nodePath );

        if ( !file_exists( $dir ) )
        {
            eZDir::mkdir( $dir, false, true );
        }

        $this->tmpFp = fopen( $this->tmpFilepath, $type );

        return (is_resource($this->tmpFp));
    }
    
    function closeTemporaryFile()
    {
    	fclose($this->tmpFp);
        
        unlink( $this->tmpFilepath );
        
        if ( !eZDir::cleanupEmptyDirectories( dirname( $this->tmpFilepath ) ) )
        {
            eZDebug::writeError('error cleaning directory', 'eZFTPInOut::write');
        }
    }
    
    public function writeTemporaryFile( $data )
    {
    	fwrite( $this->tmpFp, $data, strlen( $data ) );
    }
    
    public function createContentFromTemporaryFile()
    {
        // If there was an actual file:
        if ( !$this->tmpFilepath  )
        {
            eZDebug::writeError ( "Unable to get '$tempFile'", 'eZFTPInOut::write' );
            return;
        } 
        $parentNode = $this->fetchParentNodeByTranslation( $this->nodePath );
        if ( $parentNode == null )
        {
            // The node does not exist, so we cannot put the file
            return;
        }

        $parentNodeID = $parentNode->attribute( 'node_id' );

        // We need the MIME-Type to figure out which content-class we will use
        $mimeInfo = eZMimeType::findByURL( $this->nodePath );
        $mime = $mimeInfo['name'];

        $ini = eZINI::instance( 'ezftp.ini' );
        $defaultObjectType = $ini->variable( 'PutSettings', 'DefaultClass' );

        //$existingNode = $this->fetchNodeByTranslation( $this->nodePath );
        
        $success = true;
        
        //$existingNode = $this->fetchNodeByTranslation( $this->nodePath );
        
        $upload = new eZContentUpload();
        if ( !$upload->handleLocalFile( $result, $this->tmpFilepath, $parentNodeID, $this->node ) )
        {
            
            foreach ( $result['errors'] as $error )
            {
                eZDebug::writeError( "Error: " . $error['description'], 'eZFTPInOut::stor' );
            }
            foreach ( $result['notices'] as $notice )
            {
                eZDebug::writeError( "Notice: " . $notice['description'], 'eZFTPInOut::put' );
            }
            
            $success = false;
        }
        
        return $success;
    }

    /**
     * Handles deletion on the content tree level.
     * It will try to find the node of the target \a $target
     *and then try to remove it (ie. move to trash) if the user is allowed.
     */
    function delete()
    {
        if ( $this->node == null )
        {
            eZDebug::writeError( "Cannot delete node/object $this->nodePath", 'eZFTPInOut::delete' );
            return false;
        }

        $this->node->removeNodeFromTree( true );
        return true;
    }


    private function perms( $path )
    {
        return 'drwxr-xr-x';
    }

    private function virtualContentFolderName()
    {
        return ezi18n( 'kernel/content', 'Content' );
    }

    private function virtualMediaFolderName()
    {
        return ezi18n( 'kernel/content', 'Media' );
    }
    
    /**
     \return An array containing the names of all folders in the virtual root.
    */
    private function virtualFolderList()
    {
        return array($this->virtualContentFolderName(), $this->virtualMediaFolderName() );
    }
    
    /**
     * \return date string formated for ftp output 
     */
    private function formatTime( $time )
    {
        //if the file has been created on this year
        if ( date( 'Y' , $time ) == date( 'Y', time() ) )
        {
            //date with time
            return date( 'M d H:i', $time );
        }
        else
        {
            //date with year
            return date( 'M d Y', $time );
        }
    }

    /**
     * \return a list of the available sites (from site.ini).
     */
    private function availableSites()
    {
        // The site list is an array of strings.
        $siteList = array();

        // Grab the sitelist from the ini file.
        $ini = eZINI::instance();
        $siteList = $ini->variable( 'SiteSettings', 'SiteList' );

        // Return the site list.
        return $siteList ;
    }

   /**
      Takes the first path element from \a $path and removes it from
      the path, the extracted part will be placed in \a $name.
      \return A string containing the rest of the path,
              the path will not contain a starting slash.
      \param $path A string defining a path of elements delimited by a slash,
                   if the path starts with a slash it will be removed.
      \param[out] $element The name of the first path element without any slashes.

      \code
      $path = '/path/to/item/';
      $newPath = eZWebDAVContentServer::splitFirstPathElement( $path, $root );
      print( $root ); // prints 'path', $newPath is now 'to/item/'
      $newPath = eZWebDAVContentServer::splitFirstPathElement( $newPath, $second );
      print( $second ); // prints 'to', $newPath is now 'item/'
      $newPath = eZWebDAVContentServer::splitFirstPathElement( $newPath, $third );
      print( $third ); // prints 'item', $newPath is now ''
      \endcode
    */
    private function splitFirstPathElement( $path, &$element )
    {
        if ( $path[0] == '/' )
            $path = substr( $path, 1 );
        $pos = strpos( $path, '/' );
        if ( $pos === false )
        {
            $element = $path;
            $path = '';
        }
        else
        {
            $element = substr( $path, 0, $pos );
            $path = substr( $path, $pos + 1 );
        }
        return $path;
    }
    
    private function rootLs()
    {
        // At the end: we'll return an array of entry-arrays.
        $entries = array();

        // Get list of available sites.
        $sites = $this->availableSites();

        // For all available sites:
        foreach ( $sites as $site )
        {
            // Set up attributes for the virtual site-list folder:
            $entry = array();
            $entry['name']     = $this->encodePath( $site );
            $entry['size']     = '4096';
            $entry['owner'] = 'ezpublish';
            $entry['group'] = 'ezpublish';
            $entry['time'] = $this->formatTime( filectime( 'settings/siteaccess/' . $site ) );
            $entry['perms']    = 'dr-xr-x---';

            $entries[] = $entry;
        }

        return $entries;
    }
    
    private function siteLs( $site )
    {
        // At the end: we'll return an array of entry-arrays.
        $entries = array();

        $defctime = filectime( 'settings/siteaccess/' . $site );

        $list = $this->virtualFolderList();
        foreach ( $list as $filename )
        {
            $entry = array();
            $entry['name']     = $this->encodePath( $filename );
            $entry['size']     = '4096';
            $entry['owner'] = 'ezpublish';
            $entry['group'] = 'ezpublish';
            $entry['time'] = $this->formatTime( $defctime );
            $entry['perms']    = 'dr-xr-x---';
            $entries[] = $entry;
        }

        return $entries;
    }
    
    /**
     * @private
     * @return A path that corresponds to the internal path of nodes.
     */
    private function internalNodePath( $virtualFolder, $path )
    {
        // All root nodes needs to prepend their name to get the correct path
        // except for the content root which uses the path directly.
        if ( $virtualFolder == $this->virtualMediaFolderName() )
        {
            $nodePath = 'media';
            if ( strlen( $path ) > 0 )
                $nodePath .= '/' . $path;
        }
        else
        {
            $nodePath = $path;
        }
        return $nodePath;
    }

    /**
      Attempts to fetch a possible/existing node by translating
      the inputted string/path to a node-number.
    */
    private function fetchNodeByTranslation( $nodePathString )
    {

        // Get rid of possible extensions, remove .jpeg .txt .html etc..
        $nodePathString = $this->fileBasename( $nodePathString );

        // Strip away last slash
        if ( strlen( $nodePathString ) > 0 and
             $nodePathString[strlen( $nodePathString ) - 1] == '/' )
        {
            $nodePathString = substr( $nodePathString, 0, strlen( $nodePathString ) - 1 );
        }

        if ( strlen( $nodePathString ) > 0 )
        {
            //TODO convertPathToAlias trim spaces, which is not RFC2640 Compliant
            $nodePathString = eZURLAliasML::convertPathToAlias( $nodePathString );
        }

        // Attempt to get nodeID from the URL.
        $nodeID = eZURLAliasML::fetchNodeIDByPath( $nodePathString );
        if ( !$nodeID )
        {
            return false;
        }

        // Attempt to fetch the node.
        $node = eZContentObjectTreeNode::fetch( $nodeID );

        // Return the node.
        return $node;
    }

    /**
      \return The string \a $name without the final suffix (.jpg, .gif etc.)
    */
    private function fileBasename( $name )
    {
        $pos = strrpos( $name, '.' );
        if ( $pos !== false )
        {
            $name = substr( $name, 0, $pos );
        }
        return $name;
    }
    
    /**
      Gets and returns the content of an actual node.
      List of other nodes belonging to the target node
      (one level below it) will be returned.
    */
    private function folderLS( &$node )
    {
        // We'll return an array of entries (which is an array of attributes).
        $entries = array();

        //renew current logged in user 
        $user = &$this->client->getUser();
        eZUser::setCurrentlyLoggedInUser( $user, $user->id() );
        
        $this->restorePolicyLimitationRuntimeCache( $user->id() );
        
        // Get all the children of the target node.
        $subTree = $node->subTree( array ( 'Depth' => 1 ) );
        
        $this->savePolicyLimitationRuntimeCache( $user->id() );
        
        // Build the entries array by going through all the
        // nodes in the subtree and getting their attributes:
        foreach ( $subTree as $someNode )
        {
            $entries[] = $this->fetchNodeInfo( $someNode );
        }

        // Return the content of the target.
        return $entries;
    }
    
    /**
     * Policy limitation cache restore function
     * 
     * On eZFTP, we have one process managing every users
     * so if we don't clear it, the policy limitation cache is shared
     * with every users. It can cause bug prone.
     * 
     * To not slow down the execution we use a backup instead of clearing it...
     * 
     * Each time a function using $GLOBALS['ezpolicylimitation_list'] is called
     * (eZContentObjectTreeNode::subTreeByNodeID, eZContentObjectTreeNode::subTree
     * or eZUser::hasAccessToView...), we need to call
     * restorePolicyLimitationRuntimeCache before and savePolicyLimitationRuntimeCache after
     */
    private function restorePolicyLimitationRuntimeCache( $userId )
    {
        if ( isset( $GLOBALS['ezftp_ezpolicylimitation_list'][$userId] ) )
        {
            $GLOBALS['ezpolicylimitation_list'] = $GLOBALS['ezftp_ezpolicylimitation_list'][$userId];
        }
        elseif ( isset ( $GLOBALS['ezpolicylimitation_list'] ) )
        {
            unset( $GLOBALS['ezpolicylimitation_list'] );
        }
    }
    
    /**
     *  Policy limitation cache save function
     */
    private function savePolicyLimitationRuntimeCache( $userId )
    {
        if ( isset( $GLOBALS['ezpolicylimitation_list'] ) )
        {
            //save runtime policy limitation cache
            $GLOBALS['ezftp_ezpolicylimitation_list'][$userId] = $GLOBALS['ezpolicylimitation_list'];
        
            //clear policy limitation runtime cache
            unset( $GLOBALS['ezpolicylimitation_list'] );
        }
    }

    /**
      Gathers information about a given node (specified as parameter).
    */
    private function fetchNodeInfo( &$node )
    {
        // When finished, we'll return an array of attributes/properties.
        $entry = array();

        $object = $node->attribute( 'object' );

        // By default, everything is displayed as a folder:
        // Trim the name of the node, it is in some cases whitespace in eZ Publish   
        $entry['name']     = $node->attribute( 'name' );
        $entry['size']     = '4096';
        $entry['owner'] = 'ezpublish';
        $entry['group'] = 'ezpublish';
        $entry['time'] = $this->formatTime( $object->attribute( 'published' ) );
        $entry['perms']    = 'dr-xr-x---';
        
        //include_once( 'kernel/classes/ezcontentupload.php' );
        $upload = new eZContentUpload();
        $info = $upload->objectFileInfo( $object );
        $suffix = '';

        if (  $this->isFolder( $node ) )
        {
            // We do nothing, the default is to see it as a folder
        }
        else if ( $info )
        {
            $filePath = $info['filepath'];

            $entry['size'] = false;
            if ( isset( $info['filesize'] ) )
                $entry['size'] = $info['filesize'];

            // Fill in information from the actual file if they are missing.
            $file = eZClusterFileHandler::instance( $filePath );
            if ( !$entry['size'] and $file->exists() )
            {
                $entry['size'] = $file->size();
            }
            
            if ( !isset( $info['mime_type'] )  )
            {
                $mimeInfo = eZMimeType::findByURL( $filePath );
                $suffix = $mimeInfo['suffix'];
                if ( strlen( $suffix ) > 0 )
                    $entry['name'] .= '.' . $suffix;
            }
            else
            {
                // eZMimeType returns first suffix in its list
                // this could be another one than the original file extension
                // so let's try to get the suffix from the file path first
                $suffix = eZFile::suffix( $filePath );
                if ( !$suffix )
                {
                    $mimeInfo = eZMimeType::findByName( $info['mime_type'] );
                    $suffix = $mimeInfo['suffix'];
                }
                if ( strlen( $suffix ) > 0 )
                    $entry['name'] .= '.' . $suffix;
            }

            
            if ( $file->exists() )
            {
                $entry['time'] = $this->formatTime( $file->mtime() );
                $entry['perms']    = '-r-xr-x---';
            }
        }
        
        $entry['name']  = $this->encodePath( $entry['name'] );
        return $entry;
    }



    /**
      \return \c true if the object \a $object should always be considered a folder.
    */
    private function isFolder( &$node )
    {
        $object = $node->attribute( 'object' );
        $class = $object->contentClass();
        $classIdentifier = $class->attribute( 'identifier' );
        
        if ( $this->folderClasses === null )
        {
            $ini = eZINI::instance( 'ezftp.ini' );
            $folderClasses = array();
            if ( $ini->hasGroup( 'eZFTPSettings' ) and
                 $ini->hasVariable( 'eZFTPSettings', 'FolderClasses' ) )
            {
                $folderClasses = $ini->variable( 'eZFTPSettings', 'FolderClasses' );
            }
            $this->folderClasses = $folderClasses;
        }
        return in_array( $classIdentifier, $this->folderClasses );
    }
    
    private function encodePath( $path )
    {
    	print_r( $path . ": " );
        //UTF-8 support
        if ( $this->client->isUtf8Enabled() )
        {
            $outputCharset = 'UTF-8';   
        }
        else
        {
            $outputCharset = 'iso-8859-1';
        }
        $codec = eZTextCodec::instance( false, $outputCharset );
        $path = $codec->convertString( $path );
        print_r( $path ."\n" );
        return $path;
    }
    
    private function decodePath( $path )
    {
        //UTF-8 support
        if ( $this->client->isUtf8Enabled() )
        {
            $inputCharset = 'UTF-8';   
        }
        else
        {
            $inputCharset = 'iso-8859-1';
        }
        $codec = eZTextCodec::instance( $inputCharset, false );
        $path = $codec->convertString( $path );
        return $path;
    }
   
    /**
     * Checks if the current user has access rights to site \a $site.
     * @return boolean true if the user proper access.
     */
    private function userHasSiteAccess( $site )
    {
        //renew current logged in user
        $user = &$this->client->getUser();
        eZUser::setCurrentlyLoggedInUser( $user, $user->id() );
        
        $result = $this->client->getUser()->hasAccessTo( 'user', 'login' );
        $accessWord = $result['accessWord'];

        if ( $accessWord == 'limited' )
        {
            $hasAccess = false;
            $policyChecked = false;
            foreach ( array_keys( $result['policies'] ) as $key )
            {
                $policy =& $result['policies'][$key];
                if ( isset( $policy['SiteAccess'] ) )
                {
                    $policyChecked = true;
                    if ( in_array( eZSys::ezcrc32( $site ), $policy['SiteAccess'] ) )
                    {
                        $hasAccess = true;
                        break;
                    }
                }
                if ( $hasAccess )
                    break;
            }
            if ( !$policyChecked )
                $hasAccess = true;
        }
        else if ( $accessWord == 'yes' )
        {
            $hasAccess = true;
        }
        else if ( $accessWord == 'no' )
        {
            $hasAccess = false;
        }
        return $hasAccess;
    }
    
    function fetchParentNodeByTranslation( $nodePathString )
    {
        // Strip extensions. E.g. .jpg
        $nodePathString = $this->fileBasename( $nodePathString );

        // Strip away last slash
        if ( strlen( $nodePathString ) > 0 and
             $nodePathString[strlen( $nodePathString ) - 1] == '/' )
        {
            $nodePathString = substr( $nodePathString, 0, strlen( $nodePathString ) - 1 );
        }

        $nodePathString = $this->splitLastPathElement( $nodePathString, $element );

        if ( strlen( $nodePathString ) == 0 )
            $nodePathString = '/';

        $nodePathString = eZURLAliasML::convertPathToAlias( $nodePathString );

        // Attempt to translate the URL to something like "/content/view/full/84".
        $translateResult = eZURLAliasML::translate( $nodePathString );

        // handle redirects
        while ( $nodePathString == 'error/301' )
        {
            $nodePathString = $translateResult;

            $translateResult = eZURLAliasML::translate( $nodePathString );
        }

        // Get the ID of the node (which is the last part of the translated path).
        if ( preg_match( "#^content/view/full/([0-9]+)$#", $nodePathString, $matches ) )
        {
            $nodeID = $matches[1];
        }
        else
        {
            $nodeID = 2;
        }

        // Attempt to fetch the node.
        $node = eZContentObjectTreeNode::fetch( $nodeID );

        // Return the node.
        return $node;
    }

    /*!
      Takes the last path element from \a $path and removes it from
      the path, the extracted part will be placed in \a $name.
      \return A string containing the rest of the path,
              the path will not contain the ending slash.
      \param $path A string defining a path of elements delimited by a slash,
                   if the path ends with a slash it will be removed.
      \param[out] $element The name of the first path element without any slashes.

      \code
      $path = '/path/to/item/';
      $newPath = eZWebDAVContentServer::splitLastPathElement( $path, $root );
      print( $root ); // prints 'item', $newPath is now '/path/to'
      $newPath = eZWebDAVContentServer::splitLastPathElement( $newPath, $second );
      print( $second ); // prints 'to', $newPath is now '/path'
      $newPath = eZWebDAVContentServer::splitLastPathElement( $newPath, $third );
      print( $third ); // prints 'path', $newPath is now ''
      \endcode
    */
    function splitLastPathElement( $path, &$element )
    {
        $len = strlen( $path );
        if ( $len > 0 and $path[$len - 1] == '/' )
            $path = substr( $path, 0, $len - 1 );
        $pos = strrpos( $path, '/' );
        if ( $pos === false )
        {
            $element = $path;
            $path = '';
        }
        else
        {
            $element = substr( $path, $pos + 1 );
            $path = substr( $path, 0, $pos );
        }
        return $path;
    }
    
    static function tempDirectory()
    {
        $tempDir = eZSys::varDirectory() . '/ezftp/tmp';
        if ( !file_exists( $tempDir ) )
        {
            eZDir::mkdir( $tempDir, eZDir::directoryPermission(), true );
        }
        return $tempDir;
    }
    
    //eZPublish classes acting as folder 
    private $folderClasses;
    private $client;
    private $nodePath;
    private $site;
    //private virtualFolder = false;
    private $node;
    private $exists;
    private $canRead;
    private $canModify;
    private $canCreate;
    private $canDelete;
    private $tmpFp;
    private $tmpFilepath;
}
?>