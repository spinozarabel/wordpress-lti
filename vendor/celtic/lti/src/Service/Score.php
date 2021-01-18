<?php

namespace ceLTIc\LTI\Service;

use ceLTIc\LTI;

/**
 * Class to implement the Score service
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @copyright  SPV Software Products
 * @license  http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class Score extends AssignmentGrade
{

    /**
     * Access scope.
     */
    public static $SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';

    /**
     * Class constructor.
     *
     * @param Tool         $tool       Tool object for this service request
     * @param string       $endpoint   Service endpoint
     */
    public function __construct($tool, $endpoint)
    {
        parent::__construct($tool, $endpoint, '/scores');
        $this->scope = self::$SCOPE;
        $this->mediaType = 'application/vnd.ims.lis.v1.score+json';
    }

    /**
     * Submit an outcome for a user.
     *
     * @param LTI\Outcome     $ltiOutcome   Outcome object
     * @param LTI\User        $user         User object
     *
     * @return bool  True if successful, otherwise false
     */
    public function submit($ltiOutcome, $user)
    {
        $score = $ltiOutcome->getValue();
        if (!is_null($score)) {
            $json = array(
                'scoreGiven' => $score,
                'scoreMaximum' => $ltiOutcome->getPointsPossible(),
                'comment' => $ltiOutcome->comment,
                'activityProgress' => $ltiOutcome->activityProgress,
                'gradingProgress' => $ltiOutcome->gradingProgress,
            );
        } else {
            $json = array(
                'activityProgress' => 'Initialized',
                'gradingProgress' => 'NotReady',
            );
        }
        $json['userId'] = $user->ltiUserId;
        $date = new \DateTime();
        $json['timestamp'] = date_format($date, 'Y-m-d\TH:i:s.uP');
        $data = json_encode($json);
        $http = $this->send('POST', null, $data);

        return $http->ok;
    }

}
