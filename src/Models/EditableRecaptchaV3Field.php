<?php
namespace NSWDPC\SpamProtection;

use SilverStripe\Forms\TextField;
use SilverStripe\Forms\HeaderField;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\Control\Controller;
use Firesphere\RangeField\RangeField;

/**
 * EditableRecaptchaV3Field
 * A field that adds reCAPTCHAv3 support to a user defined form
 * @author James <james.ellis@dpc.nsw.gov.au>
 */
class EditableRecaptchaV3Field extends EditableFormField
{
    private static $singular_name = 'reCAPTCHA v3 field';

    private static $plural_name = 'reCAPTCHA v3 fields';

    private static $has_placeholder = false;

    /**
     * Database fields
     * @var array
     */
    private static $db = [
        'Score' => 'Int',// 0-100
        'Action' => 'Varchar(255)'// custom action
    ];

    /**
     * Add default values to database
     * @var array
     */
    private static $defaults = [
        'Score' => 70,
        'Action' => 'submit',
    ];

    /**
     * Defines the database table name
     * @var string
     */
    private static $table_name = 'EditableRecaptchaV3Field';

    /**
     * Event handler called before writing to the database.
     */
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if(!is_numeric($this->Score)) {
            $this->Score = $this->config()->get('defaults')['Score'];
        }

        if($this->Score < 0) {
            $this->Score = 0;
        }
        if($this->Score > 100) {
            $this->Score = 100;
        }

        if(!$this->Action) {
            $this->Action = $this->config()->get('defaults')['Action'];
        }

        // remove disallowed characters
        $this->Action = TokenResponse::formatAction($this->Action);

        /**
         * never require this field as it could cause weirdness with frontend validators
         */
        $this->Required = 0;

        // no placeholder
        $this->Placeholder = "";

        // always require a default title
        if(!$this->Title) {
            $this->Title = _t( 'NSWDPC\SpamProtection.RECAPTCHAv3', 'Recaptcha v3');
        }
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();
        $this->DisplayRules()->removeAll();
    }

    /**
     * return range e.g for {@link Firesphere\RangeField\RangeField}
     * @return array
     */
    protected function getRange() {
        $min = 0;
        $max = 100;
        $steps = 5;
        $i = 0;
        $range = [];
        $range [ $min ] = $min;
        while($i < $max) {
            $i += $steps;
            $range[ number_format($i, 2) ] = $i;
        }
        $range [ $max ] = $max;
        return $range;
    }

    /**
     * CMS Fields
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->removeByName([
            'ExtraClass', // this field can't have extra CSS stuff as it is invisible
            'Default',// there is no default value for this field
            'RightTitle',// there is no right title for this field
            'Required',// this field is always required for the form submission
            'DisplayRules'// this field is always required, therefore no display rules
        ]);

        $range_field = RangeField::create(
            'Score',
            _t( 'NSWDPC\SpamProtection.SCORE_HUMAN', 'Choose a score'),
            [ $this->Score ]
        )->setDescription(
            "<br>" .
            _t( 'NSWDPC\SpamProtection.SCORE_DESCRIPTION_HUMAN',
            "A submission with a score below the selected value will be allowed.<br>"
            . ' A submission score of 0 will almost certainly be a valid form submission,'
            . ' while a submission score of 100 will almost certainly be from an automated form submission')
        );
        $range_field->setMin(0);
        $range_field->setMax(100);
        $range_field->setRange( $this->getRange() );
        $range_field->setStep(10);
        $range_field->setSnap(true);
        $range_field->setDecimalPlaces(0);
        $range_field->setShowPips(true);

        $fields->addFieldsToTab(
                "Root.Main", [
                    HeaderField::create(
                        'reCAPTCHAv3Header',
                        _t( 'NSWDPC\SpamProtection.RECAPTCHA_SETTINGS', 'reCAPTCHA v3 settings')
                    ),
                    $range_field,
                    TextField::create(
                        'Action',
                        _t( 'NSWDPC\SpamProtection.ACTION_HUMAN', 'Set a custom action')
                    )->setDescription(
                        _t( 'NSWDPC\SpamProtection.ACTION_DESCRIPTION',
                        'This is used for analytics in the reCAPTCHA console. Allowed characters are \'a-z 0-9 /\' '
                        . 'and it may not be personally identifiable'))

                ]
        );
        return $fields;
    }

    /**
     * Return the form field with configured score and action
     * @return RecaptchaV3Field
     */
    public function getFormField()
    {
        $parent_form_identifier = "";
        if($parent = $this->Parent()) {
            $parent_form_identifier = $parent->URLSegment;
        }
        $field_template = EditableRecaptchaV3Field::class;
        $field_holder_template = EditableRecaptchaV3Field::class . '_holder';
        $field = RecaptchaV3Field::create($this->Name, $this->Title)
            ->setScore( round( ($this->Score / 100), 2) ) // format for the reCAPTCHA API 0.00->1.00
            ->setExecuteAction($parent_form_identifier . "/" . $this->Action, true)
            ->setFieldHolderTemplate($field_holder_template)
            ->setTemplate($field_template);
        $this->doUpdateFormField($field);
        return $field;
    }

    /**
     * Store the score/action/hostname (except token) as the submitted value
     * We don't need or want the token
     * @return string
     */
    public function getValueFromData($data)
    {
        // this is a new instance of the field
        $response = $this->getFormField()->getResponseFromSession();
        unset($response['token']);
        $value = json_encode($response);
        return $value;
    }

}