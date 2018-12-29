<?php

/* 
- survey - get a list of survey in json
  - get preview URL of the survey from survey api
  - make a new array, with title, preview URL, date etc, for displaying survey
  - display the response number first, upon click, 
    - download the csv file

- response - loop the list and get bulk responses for each survey
  - save the bulk response into a file? 
    - step 1, one bulk response per file
    - step 2, all bulk responses one file
    - step 3
      - upon request for responses for one survey
      - create the csv file of all responses for that survey
  
- enhancement
  - local cache, can't install memcache, do my own 
    - 1. make a mode, if file exist, do local, get from local file, otherwise, do remote api - done
    - 2. put a parameter on url, ?update, to update
      - save to a local file - done
    - 3. cron job, update background, so users get faster response - dones
*/    

// start of the program here
// get survey page name
$aURI = explode ('/', $_SERVER['REQUEST_URI']);
$__sPage = $aURI [count ($aURI)-2];
$bForceUpdate = strtolower ($_SERVER['QUERY_STRING']) == 'update' ? 1 : 0;

?>
<html><head>
<title>Survey Monkey for CP <?=$__sPage?></title>
<link type="text/css" rel="stylesheet" href="https://secure.surveymonkey.com/assets/userweb/smlib.ui-global-bundle-min.55d41bad.css" />
<!--[if lte IE 9]>
<link type="text/css" rel="stylesheet" href="https://secure.surveymonkey.com/assets/userweb/smlib.globaltemplates-base_nonresponsive-bundle-min.ab94fc2a.css" />
<![endif]-->
<!--[if (gt IE 9)|(!IE)]><!-->
<link type="text/css" rel="stylesheet" href="https://secure.surveymonkey.com/assets/userweb/smlib.globaltemplates-base_nonresponsive-bundle-min.ab94fc2a.css" />
<!--<![endif]-->
<link type="text/css" rel="stylesheet" href="https://secure.surveymonkey.com/assets/userweb/smlib.globaltemplates-mobile_banner-bundle-min.ffee3094.css" />
<link type="text/css" rel="stylesheet" href="https://secure.surveymonkey.com/assets/userweb/userweb-li-home-bundle-min.e9dd1c28.css" />
<link type="text/css" rel="stylesheet" href="https://secure.surveymonkey.com/assets/userweb/smlib.ui-global-pro-bundle-min.bce09b9c.css" />
<link type="text/css" rel="stylesheet" href="https://secure.surveymonkey.com/assets/userweb/smlib.featurecomponents-group-templates-detail-bundle-min.6401cca0.css" />
</head>
<body>
<?php

$oSurveyMonkeyAPI = new Sm_api();
$oSurveyMonkeyAPI->print_all_surveys(); // main call to display all surveys
// end of script

// Survey Monkey API 
class Sm_api {
  private $sToken, $iUpdateInterval;

  function __construct() { // constructor
    global $__sPage;
    $aConfigs = parse_ini_file ('/var/account/sm.ini', true);
    $this->sToken = $aConfigs['token'][$__sPage];
    $this->iUpdateInterval = $aConfigs['update']['interval'];
  }
  
  /**
   * one of only 2 public methods called outside the class, the top level call, top parent call, called user directly
   * will print a table of all surveys, with 2 set of links 
   * 1. links one - show survey on SM site  links two - download responses to a csv file
   */
  public function print_all_surveys() {
    global $__sPage;
    $aSurveys = $this->get_surveys_list($this->iUpdateInterval); // 24 hours interval update
    array_pop ($aSurveys); // last element is a last update time stamp I put 
    
    $sTable = "<center><h1>Survey Monkey for CP $__sPage</h1><br><br><table><tr><th>Surveys <th>Responses <tr>";
    foreach ($aSurveys as $oSurvey) {
      $sTable .= "<tr><td><a href=$oSurvey->previewURL target=_blank title='View survey questions in a new window'>$oSurvey->title</a> 
      <br>Created $oSurvey->date_created, modified $oSurvey->date_modified 
      <td> <a href=?survey_id=$oSurvey->ID target=_blank title='Download survey responses to a CSV spreadsheet file'> $oSurvey->response_count </a>";
    }
    $sTable .= '</table><br><br>';
    echo $sTable;
  }
  
  /**
   * one of only 2 public methods called outside the class, the top level call, top parent call, called user directly
   * get all responses from all respondents for a survey, called by a link created by print_all_surveys(); 
   *
   * flow: go through survey first, then go thru each response to that survey
   *
   * @PARAM integer - $_iSurveyID 
   * @RETURN string $sResponsesForAllRespondents, all responses for the survey + survey title
   */
  public function get_responses_to_a_survey ($_iSurveyID) {
    // get the questions of the survey, ready for response to match
    $aQuestions = $this->survey_detail($_iSurveyID); // sub function test local file first
    $sSurveyTitle = $this->clean_string($aQuestions->nickname?:$aQuestions->title, false); // get title before it drills down, survey title used for csv file name

    // get the responses ready for loop to find, corresponding to each question
    $aBulkResponsJSON = $this->bulk_response($_iSurveyID); // sub function checks if local file exists, and if late enough before calling API
    $aResponsesForAllRespondents = $aBulkResponsJSON->data;
    
    // now go thru each question, getting header and response for each question
    // building array of record (1 id, 1 header, multiple responses): 1 header = many responses
    $iResponseIndexID = 0; // my own index, for 1st column of csv
    $aResponsesArray = [];
    
    foreach ($aQuestions->pages as $aQuestionPage) { // go thru all pages
      foreach ($aQuestionPage->questions as $oQuestion) { // all questions
        // check for not real question
        // if (($oQuestion->subtype == 'descriptive_text') and ($oQuestion->family == 'presentation')) { 
        if ($oQuestion->family == 'presentation') { 
          // echo "\n skipped header question :" ; print_r ($oQuestion->headings);
          continue; // could find more criteria, isset (->mainly display_options) then show=false
        }
        
        // proceed with proper/valid/real questions with potential answer
        $aResponsesArray[$iResponseIndexID] = new stdClass();
        $sHeading = strip_tags($oQuestion->headings[0]->heading); // could have html tag there even in valid questions
        $aResponsesArray[$iResponseIndexID]->QuestionHeader = $sHeading;
        
        // now build the responses array for this question $aResponsesArray->aResponses[]
        // cycle thru all responses for this survey,  check if this question is answered in each response, by each respondent
        
        // echo "\n\n === Getting responses to question id '$oQuestion->id', text '$sHeading' ===";
        $aResponses_to_1_question = $this->find_all_responses_to_a_question ($oQuestion->id, $aResponsesForAllRespondents, $oQuestion);
        // echo "\n\n  got responses to question id '$oQuestion->id', text '$sHeading' "; print_r ($aResponses_to_1_question);
        
        $aResponsesArray[$iResponseIndexID]->QuestionResponses = $aResponses_to_1_question;
        $iResponseIndexID++;
      }
    }
    
    // transfer to another array for easy output, create header first
    $aOutputCSV = [];
    $iOutputArrayIndex = 0;
    $aOutputCSV[$iOutputArrayIndex] = 'Responses,';
    foreach ($aResponsesArray as $oResponse) {
      $aOutputCSV[$iOutputArrayIndex] .= $this->clean_string($oResponse->QuestionHeader) . ',';
    }
    $aOutputCSV[$iOutputArrayIndex] = rtrim(trim($aOutputCSV[$iOutputArrayIndex]), ',');

    // create an array of responses/answers to match questions
    $iResponseCount = count ($aBulkResponsJSON->data); 
    
    for ($i = 0; $i< $iResponseCount; $i++) {
      $iCombinedArrayIndex = $i+1;
      $aOutputCSV[$iCombinedArrayIndex] = "$iCombinedArrayIndex,";
      foreach ($aResponsesArray as $oResponse) { // loop thru each header, 12
        $sResponse = $this->clean_string($oResponse->QuestionResponses[$i]);
        $aOutputCSV[$iCombinedArrayIndex] .= "$sResponse," ;
      }
      // remove the trailing comma, to accommodate multiple commans in a column, in excel 
      $aOutputCSV[$iCombinedArrayIndex] = rtrim(trim($aOutputCSV[$iCombinedArrayIndex]), ',');
    }
    
    // now construct the big string
    $sResponsesForAllRespondents = '';
    foreach ($aOutputCSV as $sResponse) {
      $sResponsesForAllRespondents .= $sResponse . "\n";
    }

    return "$sResponsesForAllRespondents|$sSurveyTitle";
  } // get_responses_to_a_survey()

  // get info for all surveys, for printing the table, already got the preview URL, not sure if I need response info in the array 
  // check local storage first, 
  // if not exist, or too long, or force update file, then get from remote
  // @PARAM integer $_iMaxInterSeconds - max interval time, in seconds
  // @RETURN array of all surveys title, response number, question number, created date, modified date
  // called by print_all_surveys
  private function get_surveys_list ($_iMaxInterSeconds) {
    global $bForceUpdate;
    $_iMaxInterSeconds = $_iMaxInterSeconds ?: 86400; // default 24 hours, if not specified
    if (file_exists('data/surveys-info.txt')) {
      $aSurveysInfo = unserialize (file_get_contents ('data/surveys-info.txt'));
      $iTimeDiff = time() - $aSurveysInfo['last-remote-update'];
      if (($iTimeDiff > $_iMaxInterSeconds) or file_exists ('force-update') or ($bForceUpdate)) {
        $aSurveysInfo = $this->make_surveys_array_from_remote(); // re-update $aSurveysInfo if exceed update interval
      }
    } else {
      $aSurveysInfo = $this->make_surveys_array_from_remote();
    }
    // print_r ($aSurveysInfo);  echo $iTimeDiff;
    return $aSurveysInfo;
  }

  /**
   * find all response to a question from all respondents
   * 
   * despite many nested loop, most are just 1-2 in the array
   * 
   * @PARAM question ID
   * @PARAM array of all responses data
   * @RETURN array of answers to the question ID
   *
   */
  private function find_all_responses_to_a_question ($_iQuestionID, $_aResponseDatas, $_oQuestion) {

    $aAnswersToQuestion = [];
    $iArrayIndex = 0; 
    foreach ($_aResponseDatas as $aResponsePerRespondent) {// go thru all response pages, 99% responses have 1 page only

      // return string skipped, otherwise original array
      $aAnswers = $this->get_1_response_to_1_question ($_iQuestionID, $aResponsePerRespondent); 
      $sProcessedAnswer = ''; // echo "\n analyzing aAnswers '"; print_r ($aAnswers); echo "' just printed aAnswers"; 
      
      if (is_object ($aAnswers) or is_array($aAnswers)) { // original answer, not returned by my function 
        foreach ($aAnswers as $oAnswer) { // multiple answers from 1 respondent to 1 question, can happen! 
          foreach ($oAnswer as $sAnswerType => $sAnswer) { // multiple answers from 1 respondent to 1 question, can happen! 
            if ($sAnswerType == 'choice_id') { // multiple choice, need find out the text for the ID
              $sProcessedAnswer .= $this->get_choice_id_text ($sAnswer, $_oQuestion) . ',';
            } else if ($sAnswerType == 'text') { // just text box, can use immediately
              $sProcessedAnswer .= $sAnswer .','; // straight text without further processing
            } else if ($sAnswerType == 'row_id') { // 
              // skip for now, can get the type of row if needed in future
              // echo "\n found row id, not sure how to do yet";
            }
          }
        }
      } else {
        // echo "\n not object, should be just 'skipped'? print_r (aAnswers);"; print_r ($aAnswers);
        $sProcessedAnswer = $aAnswers;
      }
      
      $sProcessedAnswer = rtrim(trim($sProcessedAnswer), ','); 
      // echo "\n\n processed answer is string: '$sProcessedAnswer' ";
      $aAnswersToQuestion [$iArrayIndex++] = $sProcessedAnswer;
    }  // go thru each respondent

    // echo "\n\n total answers found for aAnswersToQuestion = "; print_r ($aAnswersToQuestion); die; 
    return $aAnswersToQuestion; // after loops, didn't find response to this question ID
  }  // find_response_to_a_question

  /**
   * Find choice id text from question object
   * - not all question object has choice id
   * - but this object must have choice id, because the caller has find choice id response to this question
   * @PARAM integer choice id
   * @PARAM object question from survey detail api call, 1 question from there
   *
   */
  private function get_choice_id_text($_iChoiceID, $_oQuestion) {
    foreach ($_oQuestion->answers->choices as $oChoice) {
      // echo "\n in the get_choice_id_text() loop, seeking _iChoiceID, oChoice = "; print_r ($oChoice);
      if ($oChoice->id == $_iChoiceID) {
        // echo "\n\n inside get_choice_id_text(), found the text for the choice id, the text is $oChoice->text";
        return $oChoice->text;
      }
    }
    return "Text not found for choice ID $_iChoiceID"; // couldn't find the choice text for the id
  }

  /**
   * find response from 1 respondent to 1 qustion, if the respondent answered or skipped
   * narrow down to responses from 1 respondent
   * @PARAM integer, quesiton ID
   * @PARAM array, array of responses from the respondent
   * @PARAM array, response, could be multiple responses to one quesiton from 1 respondent! 
   * @RETURN string if skipped, or array of objects
   */
  private function get_1_response_to_1_question ($_iQuestionID, $_aResponsePerRespondent) {
    foreach ($_aResponsePerRespondent->pages as $aResponseAnswers) { // cycle all pages of this respondent answers
      foreach ($aResponseAnswers->questions as $oResponseAnswer) { // cycle all answers on this page

        // this is where choices are, must be here, if there is any answer from this respondent
        // $aResponsesForAllRespondents = $aBulkResponsJSON->data[n]->pages[n]->questions[n]->answers[n]

        if ($oResponseAnswer->id == $_iQuestionID) { // if answers to this question is found
          return $oResponseAnswer->answers; // return immediately, both loop, up finding
          // echo "\n\n found answer to this question ID, the answer is: "; print_r($oResponseAnswer->answers); echo "loop to next question";
          // continue;
        }

      } // go thru the answers to this question

    }  // go thru pages
    return "Skipped"; // not found after double nestped loop, all answers, all pages, for this respondent
    // echo "\n\n can not find answer to this question ID from this respondent, loop to next question ";
  }

  // called by get_surveys_list ($_iMaxInterSeconds)
  private function make_surveys_array_from_remote () {
    $aSurveys = json_decode($this->get_surveys());  // will get an array, either from local or remote
    $aSurveysInfo = [];
    
    foreach ($aSurveys->data as $oSurvey) {
			$aSurveyInfo = $this->survey_detail($oSurvey->id); // remote API call, get response #, created, modified, preview URL, 

			$oSurveyInfo = new stdClass();
			$oSurveyInfo->ID = $oSurvey->id;
			$oSurveyInfo->title = str_replace("\n", ' ', ($oSurvey->nickname ?: $oSurvey->title)); // remove carriage return from result
			$oSurveyInfo->date_created = $aSurveyInfo->date_created;
			$oSurveyInfo->date_modified = $aSurveyInfo->date_modified;
			$oSurveyInfo->response_count = $aSurveyInfo->response_count;
			$oSurveyInfo->question_count = $aSurveyInfo->question_count;
			$oSurveyInfo->previewURL = $aSurveyInfo->preview;
      
      // may add more info here, mainly response

      // add the record to a new array element
			$aSurveysInfo[] = $oSurveyInfo;
    }  // for loop

    $aSurveysInfo['last-remote-update'] = time(); // put a time stamp
    
    // serialize and save to a file
		$sJSON = serialize ($aSurveysInfo);
		file_put_contents ('data/surveys-info.txt', $sJSON); 	// print_r ($aSurveysInfo); echo $sJSON;
    return $aSurveysInfo;
  } // private function make_surveys_array_from_remote ()

  // get a list of all surveys
  // called by make_surveys_array_from_remote()
  private function get_surveys() {
      $sJSON = $this->curl_get('https://api.surveymonkey.net/v3/surveys');
      // dummy test value, avoid api call
      // $sJSON = '{"per_page":50,"total":6,"data":[{"href":"https:\/\/api.surveymonkey.net\/v3\/surveys\/124479316","nickname":"","id":"124479316","title":"Product Page Evaluation"},{"href":"https:\/\/api.surveymonkey.net\/v3\/surveys\/122380295","nickname":"","id":"122380295","title":"Your Input on 2018 Check Point\u2019s \nCustomer and Partner Events"},{"href":"https:\/\/api.surveymonkey.net\/v3\/surveys\/122891608","nickname":"CPX 2018 - San DIego ","id":"122891608","title":"Your Input on 2018 Check Point\u2019s\nCustomer and Partner Events"},{"href":"https:\/\/api.surveymonkey.net\/v3\/surveys\/122891451","nickname":"CPX 2018 Vegas ","id":"122891451","title":"Your Input on 2018 Check Point\u2019s\nCustomer and Partner Events"},{"href":"https:\/\/api.surveymonkey.net\/v3\/surveys\/121697474","nickname":"","id":"121697474","title":"Customer Satisfaction Survey"},{"href":"https:\/\/api.surveymonkey.net\/v3\/surveys\/121459626","nickname":"","id":"121459626","title":"Test Customer Satisfaction Survey"}],"page":1,"links":{"self":"https:\/\/api.surveymonkey.net\/v3\/surveys?page=1&per_page=50"}}';
      // file_put_contents ('all-surveys.txt', $sJSON); // save for later use
      return $sJSON;
  } // get_surveys()

  /**
   * get all/most details of the response, 
   * will check local version time stamp first
   * @PARAM $_iSurveyID - interger, the survey ID
   * @RETURN json array
   */ 
  private function bulk_response ($_iSurveyID) {
    $sLocalBulkResponseFile = "data/bulk_responses_{$_iSurveyID}.json";
    $bRequireUpdate = false; 

    if (file_exists ($sLocalBulkResponseFile)) {
      $iTimeStamp = filemtime ($sLocalBulkResponseFile);
      $iTimeDiff = time() - $iTimeStamp; 
      if ($iTimeDiff > $this->iUpdateInterval) {// too long, require update
        $bRequireUpdate = true;
      }
    } else { // no local file available, require update
        $bRequireUpdate = true;
    }

    if ($bRequireUpdate) {
      $sJSON = $this->curl_get("https://api.surveymonkey.net/v3/surveys/$_iSurveyID/responses/bulk");
      file_put_contents ($sLocalBulkResponseFile, $sJSON); // update or create local
    } else {
      $sJSON = file_get_contents($sLocalBulkResponseFile); // use local 
    }

    $aJSON = json_decode ($sJSON);
    return $aJSON;
  } // builk_response ($_iSurveyID)

  /**
   * get all/most details of the survey, called by make_surveys_array_from_remote()
   * will check local version time stamp
   * @PARAM $_iSurveyID - interger, the survey ID
   * @RETURN json string
   */ 
  private function survey_detail ($_iSurveyID) {
    $sLocalSurveyDetailFile = "data/survey_details_{$_iSurveyID}.json";
    $bRequireUpdate = false; 

    if (file_exists ($sLocalSurveyDetailFile)) {
      $iTimeStamp = filemtime ($sLocalSurveyDetailFile);
      $iTimeDiff = time() - $iTimeStamp;
      if ($iTimeDiff > $this->iUpdateInterval) {// too long, require update
        $bRequireUpdate = true;
      }
    } else { // no local file available, require update
        $bRequireUpdate = true;
    }

    if ($bRequireUpdate) {
      $sJSON = $this->curl_get ("https://api.surveymonkey.net/v3/surveys/$_iSurveyID/details"); 
      file_put_contents ($sLocalSurveyDetailFile, $sJSON); // update or create local
    } else {
      $sJSON = file_get_contents($sLocalSurveyDetailFile); // use local 
    }
      
    $aJSON = json_decode ($sJSON);
    return $aJSON;
  } // survey_detail ($_iSurveyID)
    
	/**
   * not used yet 2017-10-31, may be useful for summary, using rollups API
   * summary of a single response: "answered: 3, skipped 1"
   * may use later for summary 
   */
	private function survey_response_summary($_iSurveyID) {
    $sJSON = $this->curl_get("https://api.surveymonkey.net/v3/surveys/$_iSurveyID/rollups");
    return $sJSON;
  }
   
  /**
   * also called outside at the begining of output file
   */
  public function clean_string ($_s, $_bAddQuote=true) {
    $s = str_replace ("\n", ' ', $_s);
    // $s = filter_var ($s, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW);

    if ($_bAddQuote) {
      $s = '"' . $s . '"';
    }
    return $s;
  }

  // curl_get with token header
  private function curl_get ($_sURL) {
    $options = array(
      CURLOPT_RETURNTRANSFER => true,     // return web page
      CURLOPT_HEADER         => false,    // return headers in addition to content
      CURLOPT_FOLLOWLOCATION => false,    // follow redirects
      CURLOPT_ENCODING       => "",       // handle all encodings
      CURLOPT_AUTOREFERER    => true,     // set referer on redirect
      CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
      CURLOPT_TIMEOUT        => 120,      // timeout on response
      CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
      CURLINFO_HEADER_OUT    => true,
      CURLOPT_SSL_VERIFYPEER => false,    // Disabled SSL Cert checks
      CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    );    
    $aHeaders = ["Authorization:bearer " . $this->sToken ];
      
    $ch = curl_init($_sURL);
    curl_setopt_array( $ch, $options );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $aHeaders);
    
    $sCurlGet = curl_exec( $ch );
    
    $err = curl_errno( $ch );
    $errmsg = curl_error( $ch );
    $header = curl_getinfo( $ch );
    curl_close( $ch );
    return $sCurlGet;
  }
}    

