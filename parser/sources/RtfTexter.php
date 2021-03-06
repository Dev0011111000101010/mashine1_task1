<?php
/**************************************************************************************************************

    NAME
	RtfTexter.phpclass

    DESCRIPTION
	A class to extract raw text from an Rtf file.

    AUTHOR
	Christian Vigh, 04/2016.

    HISTORY
    [Version : 1.0]     [Date : 2016/04/12]     [Author : CV]
		Initial version.

    [Version : 1.0.1]   [Date : 2016/08/18]     [Author : CV]
	. Introduced the RtfException class.

    [Version : 1.0.2]   [Date : 2016/09/21]     [Author : CV]
	. The SaveTo() method was calling the non-existing AsText() method instead of AsString().

    [Version : 1.0.3]   [Date : 2016/09/30]     [Author : CV]
	. Removed the useless Reset() method.
	. The $Eol property was never set to a correct value.
	. Removed the useless $record_size parameter from the TextifyData() method.

    [Version : 1.0.4]   [Date : 2016/11/21]     [Author : CV]
	. The RtfParser::IgnoreCompounds() methods was not called.

 **************************************************************************************************************/

require_once ( dirname ( __FILE__ ) . '/RtfDocument.phpclass' ) ;
require_once ( dirname ( __FILE__ ) . '/RtfToken.phpclass' ) ;
require_once ( dirname ( __FILE__ ) . '/RtfParser.phpclass' ) ;


/*==============================================================================================================

    RtfTexter class -
        A class to extract raw text from an Rtf file.

  ==============================================================================================================*/
abstract class	RtfTexter	extends		RtfParser
   {
	// Texter options	
	const	TEXTEROPT_INCLUDE_PAGE_HEADERS		=  0x00000001 ;		// Include page headers ; note that neither headers nor footers
										// will be repeated if there is no section break
	const	TEXTEROPT_INCLUDE_PAGE_FOOTERS		=  0x00000002 ;		// Include page footers
	const	TEXTEROPT_INCLUDE_PAGE_TITLES		=  0x00000003 ;		// Include both headers and footers
	const	TEXTEROPT_USE_FORM_FEEDS		=  0x00000004 ;		// Use form feeds to separate pages
	const 	TEXTEROPT_WRAP_TEXT 			=  0x00000008 ;		// Wrap text, taking the page width into account
	const	TEXTEROPT_EOL_STYLE_DEFAULT		=  0x00000000 ;		// End-of-line style : default (PHP_EOL)
	const   TEXTEROPT_EOL_STYLE_WINDOWS		=  0x00000010 ;		// End-of-line style : Windows (crlf)
	const   TEXTEROPT_EOL_STYLE_UNIX		=  0x00000020 ;		// End-of-line style : Unix (lf)
	const	TEXTEROPT_EOL_STYLE_MASK		=  0x00000030 ;		// Mask used to isolate EOL style  bits
	const	TEXTEROPT_ALL				=  0xFFFFFFFF ;		// Enable all of the above options

	// Configuration variables
	protected	$Options ;						// Output text formatting options
	public 		$PageWidth ;						// Page width to be used if the TEXTEROPT_WRAP_TEXT option is enabled

	// EOL string
	protected	$Eol				=  PHP_EOL ;

	// All the keywords listed below can be safely ignored (and skipped) while processing Rtf contents
	protected static	$IgnoreList 	=
	   [
		'annotation',
		'atnauthor',
		'atndate',
		'atnicn',
		'atnid',
		'atnparent',
		'atnref',
		'atntime',
		'atrfend',
		'atrfstart',
	   	'bkmkend',
	   	'bkmkstart',
	   	'colorschememapping',
		'colortbl',
		'do',
		'datastore',
	   	'fldinst',
		'fldrslt',
	   	'fonttbl',
		'generator',
	   	'info',
		'latentstyles',
		'levelnumbers',
		'leveltext',
		'listlevel',
		'listoverridetable',
		'listpicture',
		'listtable',
		'mailmerge',
		'mmath',
		'mmathPr',
		'mvfmf',
		'mvfml',
		'mvtof',
		'mvtol',
		'object',
		'passwordhash',
		'pnseclvl',
		'pgptbl',
		'protusertbl',
	   	'revtbl',
		'rsidtbl',
	   	'shp',
		'stylesheet',
		'tc',
		'tcf',
		'tcl',
		'tcn',
		'themedata',
	   	'userprops',
		'wgrffmtfilter',
		'xmlns',
		'xmlnstbl',
		'xmlopen'
  	    ] ;

	// Translations for some Rtf tags ; they are always converted to html entities before being converted to UTF8
	protected static 	$TranslatedTags 	=
	   [
		'emspace' 	=>  ' ',
		'enspace'	=>  ' ',
		'qmspace'	=>  ' ',
		'emdash' 	=>  '&mdash;',
		'endash' 	=>  '&ndash;',
	   	'bullet'	=>  '&#149;',
		'lquote' 	=>  '&lsquo;',
		'rquote' 	=>  '&rsquo;',
		'ldblquote' 	=>  '&laquo;',
		'rdblquote' 	=>  '&raquo;'
	    ] ;

	// Some fucking character codes resist the translation process ; list them here to provide a reasonable translation
	// (the translated value can contain html entities, they will be converted to UTF8 during the text extraction process)
	protected static 	$TranslatedCharacters 	=
	   [
		0x93 		=>  '&laquo;',
	   	0x94 		=>  '&raquo;'
	    ] ;


	/*--------------------------------------------------------------------------------------------------------------
	
	    NAME
	        Constructor - Creates an RtfTexter object
	
	    PROTOTYPE
	        $texter		=  new RtfTexter ( $options = self::TEXTEROPT_ALL, $page_width = 80 ) ;
	
	    DESCRIPTION
	        Creates an RtfTexter object, without extracting text contents from any file (you have to use the 
		AsString() or SaveTo() methods for that).
	
	    PARAMETERS
	        $options (integer) -
	                Any combination of the following flags :

			- TEXTEROPT_INCLUDE_PAGE_HEADERS :
				Include page headers in the output (see Notes).

			- TEXTEROPT_INCLUDE_PAGE_FOOTERS :
				Include page footers in the output.

			- TEXTEROPT_INCLUDE_PAGE_TITLES :
				A synonym for : TEXTEROPT_INCLUDE_PAGE_HEADERS | TEXTEROPT_INCLUDE_PAGE_FOOTERS.

			- TEXTEROPT_USE_FORM_FEEDS :
				Use form feeds to seperate pages. Works only for new sections or new pages (\setcd
				and \page tags).

			- TEXTEROPT_WRAP_TEXT :
				Normally, all the text is written on a single line, until a new paragraph, page or
				section is started. This option ensures some basic text wrapping over several lines,
				making sure each line does not exceed $page_width columns (or the value specified
				by the $PageWidth property).

			- TEXTEROPT_EOL_STYLE_DEFAULT,
			  TEXTEROPT_EOL_STYLE_WINDOWS,
			  TEXTEROPT_EOL_STYLE_UNIX :
				Specifies the end of line characters to be used for each end of line.
				The default one is given by the PHP_EOL constant.

			- TEXTEROPT_ALL :
				Enables all of the above options.
				
	    NOTES
		. Since the RtfTexter class does not try to evaluate the current vertical position in a page,  page
		  headers and footers will only appear once per section, unless a \page tag is encountered.

	 *-------------------------------------------------------------------------------------------------------------*/
	public function  __construct ( $options = self::TEXTEROPT_ALL, $page_width = 80 )
	   {
		// Call the parent constructor with the parameters specified after the $options and $page_width parameters
		$argv 	=  func_get_args ( ) ;
		array_shift ( $argv ) ;
		array_shift ( $argv ) ;

		call_user_func_array ( [ 'parent', '__construct' ], $argv ) ;

		// Remember specified options ; allow an empty value to be specified for the $options parameter, which will
		// default to TEXTEROPT_ALL
		if  ( $options  ===  null  ||  $options  ===  false )
			$options	=  self::TEXTEROPT_ALL ;

		$this -> SetOptions ( $options ) ;
		$this -> PageWidth 	=  $page_width ;

		$this -> IgnoreCompounds ( self::$IgnoreList ) ;
	    }


	/*--------------------------------------------------------------------------------------------------------------
	
	    NAME
	        SetOptions - Sets formatting options.
	
	    PROTOTYPE
		$this -> SetOptions ( $options ) ;
	
	    DESCRIPTION
	        Defines the formatting options that will be applied during the text extraction process performed by the
		AsString() and SaveTo() methods.

	    NOTES
		The __get() and __set() magic methods are here to make available the Options and Eol properties (the
		latter one being read-only).
	
	 *-------------------------------------------------------------------------------------------------------------*/
	public function  __get ( $member )
	   {
		switch  ( $member )
		   {
			case	'Eol'		:  return ( $this -> Eol ) ;
			case	'Options'	:  return ( $this -> Options ) ;
			default :
				error ( new RtfException ( $member ) ) ;
		    }
	    }


	public function  __set ( $member, $value )
	   {
		switch ( $member )
		   {
			case	'Eol'		:
				error ( new RtfException ( $member ) ) ;

			case	'Options'	:
				$this -> SetOptions ( $value ) ;
				break ;

			default :
				error ( new RtfException ( $member ) ) ;
		    }
	    }


	protected function  SetOptions ( $options )
	   {
		$this -> Options	=  $options ;

		switch  ( $options & self::TEXTEROPT_EOL_STYLE_MASK )
		   {
			case 	self::TEXTEROPT_EOL_STYLE_UNIX :
		   		$this -> Eol 	=  "\n" ;
		   		break ;

			case 	self::TEXTEROPT_EOL_STYLE_WINDOWS :
		   		$this -> Eol 	=  "\r\n" ;
		   		break ;

			case 	self::TEXTEROPT_EOL_STYLE_DEFAULT :
		   	default :
		   		$this -> Eol 	=  PHP_EOL ;
		   }
	    }


	/*--------------------------------------------------------------------------------------------------------------
	
	    NAME
	        AsString - Extracts text contents from a PDF file.
	
	    PROTOTYPE
	        $text	=  $texter -> AsString ( ) ;
	
	    DESCRIPTION
	        Returns text contents from a PDF file.
	
	 *-------------------------------------------------------------------------------------------------------------*/
	public function  AsString ( )
	   {
	   	$text 		=  '' ;

	   	$this -> TextifyData ( $text ) ;

	   	return ( $text ) ;
	    }


	public function  __tostring ( )
	   { return ( $this -> AsString ( ) ) ; }


	/*--------------------------------------------------------------------------------------------------------------
	
	    NAME
	        SaveTo - Extracts text contents from a PDF file.
	
	    PROTOTYPE
	        $texter -> SaveTo ( $filename ) ;
	
	    DESCRIPTION
	        Saves the text contents a PDF file to the specified output file.
	
	 *-------------------------------------------------------------------------------------------------------------*/
	public function  SaveTo ( $filename )
	   {
	   	if  ( ! ( $fp = fopen ( $filename, "w" ) ) )
	   		error ( new RtfException ( "Could not open file \"$filename\" for writing." ) ) ;

		fwrite ( $fp, $this -> AsString ( ) ) ;
	   	fclose ( $fp ) ;
	    }


	/*--------------------------------------------------------------------------------------------------------------
	
	    NAME
	        TextifyData - Performs the real text extraction process.
	
	    PROTOTYPE
	        $this -> TextifyData ( &$data, $nesting_level_to_reach = false ) ;
	
	    DESCRIPTION
	        Performs the real text extraction process.
	
	    PARAMETERS
		$data (string) -
			Output string.
	
		$nesting_level_to_reach (boolean) -
			In some cases (such as when encountering \headerr or \footerr tags, which contains the text to
			be put in headers and footers for the current section), the TextifyData() method recursively
			calls itself to analyze specific Rtf contents.
			This parameter tells which Rtf recursion level marks the end of the analysis (ie, the final
			nested braces level).
	
	 *-------------------------------------------------------------------------------------------------------------*/
	protected function  TextifyData ( &$data, $nesting_level_to_reach = false )
	   {
		// Output data
		$data 				=  '' ;

		// Page headers and footers, if any
		$page_header 			=  '' ;
		$page_footer 			=  '' ;
		$got_data			=  false ;		// Headers will only be displayed if this one is true

		// The start of the document (hence, the end of the document header part) is arbitrarily fixed to the first
		// section encountered (\sectd tag)
		$rtf_header_processed 		=  false ;

		// Loop through Rtf tokens
		while  ( ( $token = $this -> NextToken ( ) )  !==  false )
		   {
//		       var_dump($token);
			$text	=  false ;

			switch ( $token -> Type )
			   {
				// Closing brace :
				//	Check if we need to stop processing in the case this function has been recursively called and 
				//	the target nesting level has been reached.
			   	case 	self::TOKEN_RBRACE :
			   		if  ( $nesting_level_to_reach  !==  false  &&  $nesting_level_to_reach  ==  $this -> NestingLevel )
			   			break 2 ;

			   		break ;

				// Control word :
				//	We check them mainly to handle page/line breaks.
			   	case 	self::TOKEN_CONTROL_WORD :
			   		switch  ( $token -> ControlWord )
			   		   {
						// \par :
						//	Line break, only if the header part has been processed.
			   		   	case 	'par' :
			   		   		if  ( $rtf_header_processed )
		   		   				$text 	=  $this -> Eol ;

			   		   		break ;

						// \page :
						//	Page break ; this is where we handle the insertion of page header and footer
						case	'page' :
							if  ( $this -> Options & self::TEXTEROPT_INCLUDE_PAGE_FOOTERS )
								$text 	=  $page_footer ;

							if  ( $this -> Options  &  self::TEXTEROPT_USE_FORM_FEEDS )
								$text	=  "\f" . $this -> Eol ;
							else
								$text 	=  $this -> Eol ;

							if  ( $this -> Options & self::TEXTEROPT_INCLUDE_PAGE_HEADERS )
								$text 	=  $page_header ;

							break ;

						// \tab or \cell :
						//	Try to simulate tab stops or table cells by inserting a tab.
			   		   	case 	'tab' :
			   		   	case 	'cell' :
			   		   		$text 	=  "\t" ;
			   		   		break ;

						// \line, \lbr or \trowd :
						//	Add an end of line (trowd means "end of table row").
			   		   	case 	'line' :
			   		   	case 	'lbr' :
			   		   	case 	'trowd' :
			   		   		$text 	=  $this -> Eol ;
			   		   		break ;

						// \headerr :
						//	Page header contents. This is where we have to recursively call ourselves to
						//	collect the contents.
			   		   	case 	'headerr' :
			   		   		$page_header	 =  '' ;
			   		   		$this -> TextifyData ( $page_header, $this -> NestingLevel - 1 ) ;
		   		   			$page_header 	.= $this -> Eol ;
		   		   			$page_header 	 = utf8_encode ( $page_header ) ;
			   		   		break ;

						// \footerr :
						//	Same, for footers.
			   		   	case 	'footerr' :
			   		   		$page_footer	 =  '' ;
			   		   		$this -> TextifyData ( $page_footer, $this -> NestingLevel - 1 ) ;
		   		   			$page_footer 	.=  $this -> Eol ;
		   		   			$page_footer 	 =  utf8_encode ( $page_footer ) ;
			   		   		break ;

						// \setcd :
						//	Mainly used to signal that the Rtf headers have been processed. This may be not
						//	exactly true but this is the least worse method I found.
			   		   	case 	'sectd' :
			   		   		$rtf_header_processed 	=  true ;
			   		   		break ;

						// A few tags related to current date/time...
						case 	'chdate' :
							$text 	=  date ( 'm.d.Y' ) ;
							break ;

						case 	'chdpl' :
							$text 	=  date ( 'l, j F Y' ) ;
							break ;

						case 	'chdpa' :
							$text 	=  date ( 'D, j M Y' ) ;
							break ;

						case 	'chtime' :
							$text 	=  date ( 'H:i:s' ) ;
							break ;

						// \u :
						//	Unicode character specification. We need to take into account the number of
						//	control symbols that follow the unicode character, given by the \uc tag.
						//	Those control symbols are simply ignored (they give the id of the code page
						//	that include the character that best matches the unicode character)
			   		   	case 	'u' :
							$width		=  $this -> GetControlWordValue ( 'uc', 1 ) ;

			   		   	 	for  ( $i = 0 ;  $i  <  $width ; $i ++ )
			   		   	 		$this -> NextToken ( ) ;

			   		   	 	$unicode_value 	=  ( integer ) $token -> Parameter ;

			   		   	 	if  ( $unicode_value  <  0 )
			   		   	 		$unicode_value 	=  - $unicode_value + 1 ;

			   		   	 	$text 	=  html_entity_decode ( '&#x' . dechex ( $unicode_value ) . ';' ) ;
			   		   	 	break ;

						// Other cases :
						//	Check if we have some last-resort translations to perform.
			   		   	default :
			   		   		if  ( isset ( self::$TranslatedTags [ $token -> ControlWord ] ) )
			   		   			$text 	=  html_entity_decode ( self::$TranslatedTags [ $token -> ControlWord ] ) ;
			   		    }

			   		break ;

				// Control symbol -
				//	Special symbols such as \~ (unbreakable space) etc.
			   	case 	self::TOKEN_CONTROL_SYMBOL :
			   		$text 	=  utf8_encode ( $token -> ToText ( ) ) ;
			   		break ;

				// Escaped char :
				//	An escaped character : \{, \\ or \}
			   	case 	self::TOKEN_ESCAPED_CHAR :
		   			$text 	=  $token -> Char ;
			   		break ;

				// Character code :
				//	Character code specification (\'xy).
			   	case 	self::TOKEN_CHAR :
//                    var_dump($token);
			   			$text 	=  $token->Char;

			   		break ;

				// Regular text data
			   	case 	self::TOKEN_PCDATA :
		   			$text 	=  utf8_encode ( $token -> ToText ( ) ) ;
			   		break ;
			    }

			// If some text has been collected, append it to the output
			if  ( $text  !==  false )
				$data	.=  $text ;

			// Add the page header (if defined), when we have found the first data ever
			if  ( $nesting_level_to_reach  ===  false  &&  ! $got_data  &&  $data  &&  $this -> Options & self::TEXTEROPT_INCLUDE_PAGE_HEADERS )
			   {
				$data		=  "$page_header$data" ;
				$got_data	=  true ;
			    }
		    }

		// Add the page footer to the last page
		if  ( $nesting_level_to_reach  ===  false )
		   {
			if  ( $this -> Options & self::TEXTEROPT_INCLUDE_PAGE_FOOTERS )
				$data 	.=  $page_footer ;

			// Reformat paragraphs, if the TEXTEROPT_WRAP_TEXT is specified
			if  ( $this -> Options & self::TEXTEROPT_WRAP_TEXT )
				$data	=  $this -> FormatParagraphs ( $data ) ;
		    }
	     }


	/*--------------------------------------------------------------------------------------------------------------
	
	    NAME
	        FormatParagraphs - Formats text contents to fit the specified page width.
	
	    PROTOTYPE
	        $text	=  $this -> FormatParagraphs ( $paragraph ) ;
	
	    DESCRIPTION
	        Formats text contents to fit the page width specified by the PageWidth property.
	
	    PARAMETERS
	        $paragraph (string) -
	                Paragraph to be formatted.
	
	    RETURN VALUE
	        The specified paragraph, reformatted to fit in the number of columns specified by the PageWidth property.
	
	 *-------------------------------------------------------------------------------------------------------------*/
	protected function  FormatParagraphs ( $data )
	   {
		$lines		=  explode ( $this -> Eol, $data ) ;
		$result		=  [] ;

		foreach  ( $lines  as  $line )
			$result []	=  $this -> FormatParagraph ( $line ) ;

		return ( implode ( $this -> Eol, $result ) ) ;
	    }


	protected function  FormatParagraph ( $line )
	   {
		$line	=  wordwrap ( $line, $this -> PageWidth, $this -> Eol, true ) ;

		return ( $line ) ;
	    }
    }



/*==============================================================================================================

    RtfStringTexter class -
        A class for extracting text contents from Rtf contents specified as a string.

  ==============================================================================================================*/
class  RtfStringTexter 		extends  RtfTexter
   {
	use 	RtfStringSupport ;


	public function  __construct ( $rtfdata, $options = self::TEXTEROPT_ALL, $page_width = 80 )
	   {
		parent::__construct ( $options, $page_width, $rtfdata ) ;
	    }
    }


/*==============================================================================================================

    RtfFileTexter class -
        A class for extracting text contents from an Rtf file.

  ==============================================================================================================*/
class  RtfFileTexter 		extends  RtfTexter
   {
    	use 	RtfFileSupport ;


    	public function  __construct ( $file, $options = self::TEXTEROPT_ALL, $page_width = 80 )
    	   {
    		parent::__construct ( $options, $page_width, $file ) ;
    	    }
    }
