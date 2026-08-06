<?php

namespace Loqate\ApiIntegration\Test\Unit\Model\Config\Source;

use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Model\Config\Source\AddressQualityIndex;
use Magento\Framework\Data\OptionSourceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;

/**
 * THE CONFIGURABILITY GUARANTEE of the address quality index threshold (LOQ-17148).
 *
 * WHAT IS BEING GUARANTEED, in the merchant's terms. Every quality bar a merchant can choose in
 * the admin form is a bar the verifier will actually accept addresses against, and every bar the
 * verifier accepts can be chosen. The first direction stops a selectable value that silently
 * rejects EVERY address - the merchant would set a quality bar, watch every import row come back
 * "Invalid address at row #N", and find nothing anywhere saying the value itself was the problem.
 * The second stops a value the verifier honours being unreachable, which is a setting that exists
 * only for whoever knows the config path.
 *
 * WHY IT MATTERS HERE AND NOW. Until LOQ-17148 the threshold was reachable only through
 * etc/config.xml's default, a data patch or bin/magento config:set - etc/adminhtml/system.xml
 * exposed no field for it - while the value is a plain core_config_data row that any of those can
 * put anything into. checkQualityIndex() compares it with <=, a STRING comparison, so a value
 * sorting above 'E' ('zzz', a lowercase 'c', 'C+') passed EVERY address and a value below 'A'
 * rejected every one; the verifier now refuses to judge against anything outside its accepted
 * list. Exposing the field as a SELECT over that same list is what stops the two lists drifting:
 * a free-text field would hand the merchant the whole space of values back again.
 *
 * WHY THIS TEST READS THE REAL ARTEFACTS. Both sides are derived, not restated: the selectable
 * values come from the source model the admin field is actually wired to, and the accepted values
 * from Validator's own list by reflection. A test that spelled out A-E would agree with itself
 * forever while the shipped XML said something else.
 *
 * FIRST TEST IN THIS SUITE TO PARSE etc/*.xml, so it is kept to the questions that cannot be
 * answered any other way rather than becoming a schema validator. There are four: is the field
 * there, is it a select over the right source, do its section/group/field ids compose the path
 * the verifier reads, and is it offered at the scope the readers can actually honour. Everything
 * behavioural about the threshold is asserted where behaviour belongs:
 * Test\Unit\Helper\ValidatorImportRunDedupeTest verifies an address at every selectable grade,
 * and ValidatorBatchVerifyCacheTest pins what an unreadable one does.
 */
class AddressQualityIndexTest extends TestCase
{
    /** Admin section, group and field the threshold must be configurable under. */
    private const SECTION_ID = 'loqate_settings';
    private const GROUP_ID = 'address_settings';
    private const FIELD_ID = 'address_quality_index';

    /**
     * The config path Validator::resolveQualityIndexThreshold() actually reads, spelled out as
     * the literal it is in the production source.
     *
     * SHARED, ON PURPOSE, with Test\Unit\Helper\ValidatorImportRunDedupeTest::AQI_CONFIG_PATH -
     * the two are the same string and are meant to be. That class's behavioural half writes this
     * path into a live configuration double and shows the verifier's verdict change, so it is
     * evidence that this literal is the one the verifier reads; this class then ties the admin
     * SECTION/GROUP/FIELD to it. Neither half is worth much alone: without the tie, renaming the
     * group in both the XML and this file's constants leaves the field writing to a path nothing
     * reads and the suite green; without the behavioural half, this is a string compared with
     * itself.
     */
    private const VERIFIER_CONFIG_PATH = 'loqate_settings/address_settings/address_quality_index';

    /**
     * The scope attributes the field must ship with, and etc/adminhtml/system.xml's own reason
     * for each: both callers of the threshold are adminhtml plugins reading it through
     * Helper\Data::getConfigValue(), a SCOPE_STORE read with NO STORE NAMED, so a per-website or
     * per-store-view override would be a knob that either does nothing or silently applies to a
     * different store view than the merchant set it against.
     *
     * @var array<string, string>
     */
    private const REQUIRED_FIELD_SCOPE = [
        'showInDefault' => '1',
        'showInWebsite' => '0',
        'showInStore' => '0',
    ];

    /**
     * The admin field must be a SELECT over a source model.
     *
     * Two assertions in one place because they are one guarantee: a field the merchant TYPES
     * into can hold any string, which is exactly the state LOQ-17148 is closing - a threshold
     * that either passes every address or rejects every address, chosen by a typo, with no
     * feedback anywhere. A select whose options come from a source model can only ever hold a
     * value that list contains.
     *
     * The group matters as well as the section, and the third assertion is what makes that
     * matter TESTABLE. Magento composes the config path a field writes to as
     * section/group/field, and Validator::resolveQualityIndexThreshold() reads one literal
     * path - so a field in the right section but the wrong group writes somewhere nothing reads,
     * and the merchant's chosen quality bar has no effect at all. Until that third assertion
     * existed, renaming the group in BOTH the XML and this file's constants left the suite
     * entirely green while doing exactly that; the field's coordinates were only ever compared
     * with themselves. See self::VERIFIER_CONFIG_PATH for the other half of the argument - why
     * that literal is known to be the one the verifier reads.
     */
    public function testTheThresholdIsAdminSelectableRatherThanFreeText(): void
    {
        $fields = $this->thresholdField();

        $this->assertCount(
            1,
            $fields,
            sprintf(
                'etc/adminhtml/system.xml must expose exactly one "%s" field in the "%s" group of the "%s" '
                . 'section. With no field at all the threshold is reachable only by data patch or CLI, so a '
                . 'value that rejects every address (or passes every address) can only be set - and can '
                . 'only be discovered - by someone who knows the config path; with more than one, two '
                . 'fields write the single path the verifier reads and the merchant cannot tell which one '
                . 'won. The path is composed of section/group/field, so the group is part of the '
                . 'requirement and not decoration.',
                self::FIELD_ID,
                self::GROUP_ID,
                self::SECTION_ID
            )
        );

        $field = $fields[0];
        $this->assertSame(
            'select',
            (string)($field['type'] ?? ''),
            'The field must be type="select". A free-text field lets a merchant configure a threshold the '
            . 'verifier cannot read, and an unreadable threshold rejects EVERY address on the batch paths '
            . '- every import row and every admin order - which is a total block presented as a data '
            . 'problem in their file.'
        );
        $this->assertSame(
            AddressQualityIndex::class,
            trim((string)$field->source_model),
            'The select must draw its options from the module\'s own address-quality-index source model. '
            . 'That is what ties what the merchant is OFFERED to what the verifier ACCEPTS; a hand-written '
            . 'option list in the XML would be a second copy of that list, free to drift.'
        );
        $this->assertSame(
            self::VERIFIER_CONFIG_PATH,
            implode('/', [self::SECTION_ID, self::GROUP_ID, self::FIELD_ID]),
            'The field\'s section/group/field coordinates must COMPOSE the path the verifier reads. '
            . 'Magento derives a field\'s config path from exactly those three ids, and '
            . 'Validator::resolveQualityIndexThreshold() reads one literal path, so a field placed in '
            . 'another group is a form control that writes to a row nothing ever loads: the merchant '
            . 'chooses a quality bar, saves, sees no error and gets no change - the shipped default keeps '
            . 'deciding every import row. Without this assertion the three ids above were only ever '
            . 'compared with the XML they were copied from, so renaming the group in both places was '
            . 'silently green.'
        );
    }

    /**
     * The field is DEFAULT SCOPE ONLY: showInDefault="1", showInWebsite="0", showInStore="0".
     *
     * THE ONLY MERCHANT-VISIBLE DECISION THIS TICKET MAKES, and until now the only one with no
     * test: mutating both zeros to "1" left the whole suite green. etc/adminhtml/system.xml
     * calls the choice "a deliberate choice rather than a copied line" and gives its reason at
     * length, so it is exactly the kind of decision that must fail loudly when reversed rather
     * than be reversed by someone copying the 1/1/1 of the AVC thresholds directly below it.
     *
     * WHY IT IS THIS AND NOT 1/1/1. Both callers of the threshold are adminhtml plugins -
     * Plugin\Admin\OrderSave and Plugin\Admin\ValidateImportAddress - and both read it through
     * Helper\Data::getConfigValue(), which is a SCOPE_STORE read with NO STORE NAMED. Neither
     * names the store view of the order it is judging or of the customers being imported, so
     * whatever store the admin area resolves as current decides the value. Offering a per-website
     * or per-store-view override would therefore be a control that either does nothing or
     * silently applies to a different store view than the merchant set it against - the same
     * class of defect, a setting whose effect cannot be predicted from the form, that LOQ-17148
     * exists to close.
     *
     * IF YOU ARE HERE BECAUSE THIS FAILED: widening the scope needs the READERS changed first,
     * to name the store they are judging (Helper\Data::getConfigValueForStore() exists for
     * precisely that). The cache key already namespaces per store view and does not need
     * touching. Widening the field alone ships the knob without the behaviour.
     */
    public function testTheThresholdIsConfigurableAtDefaultScopeOnly(): void
    {
        $field = $this->thresholdField()[0];

        $scope = [];
        foreach (array_keys(self::REQUIRED_FIELD_SCOPE) as $attribute) {
            $scope[$attribute] = (string)($field[$attribute] ?? '');
        }

        $this->assertSame(
            self::REQUIRED_FIELD_SCOPE,
            $scope,
            'The threshold field must be shown at DEFAULT scope only. showInWebsite="1" or showInStore="1" '
            . 'offers the merchant an override that cannot work: both readers resolve the value through a '
            . 'SCOPE_STORE read with no store named, so a per-view value applies to whichever store view '
            . 'the admin area happens to resolve as current - not to the one the merchant set it against, '
            . 'and not to the order or the import being judged.'
        );
        $this->assertSame(
            ['showInDefault' => '1', 'showInWebsite' => '0', 'showInStore' => '0'],
            self::REQUIRED_FIELD_SCOPE,
            'Guard on the expectation itself, spelled out rather than referenced: this test is worth '
            . 'nothing if the constant it compares against is edited to match a widened field. Changing '
            . 'BOTH lines is the deliberate act the XML comment asks for.'
        );
    }

    /**
     * Every threshold a merchant can select must be one the verifier accepts.
     *
     * This is the direction that produces the silent total block: a selectable value outside the
     * verifier's accepted list makes checkQualityIndex() refuse to judge at all, so EVERY address
     * is rejected - reported to the merchant as an invalid row rather than as a bad setting.
     *
     * WHAT THIS PAIR OF TESTS ACTUALLY IS, said plainly because the name suggests more. It is a
     * DRIFT GUARD ON A DERIVATION, not a comparison of two independent artefacts.
     * AddressQualityIndex::toOptionArray() derives its values FROM Validator::VALID_QUALITY_INDEXES,
     * so as long as the derivation is a straight projection both directions hold by construction
     * and this one in particular cannot fail. It is kept for what it catches when that stops
     * being true: the day the source model is hand-written, filtered, re-ordered into a
     * hard-coded list, or given a placeholder option, the two sides stop being one artefact and
     * these become real comparisons. Direction 2 -
     * testEveryThresholdTheVerifierAcceptsIsSelectable() - has teeth sooner, because a FILTERED
     * derivation (a "Not recommended" grade quietly dropped from the dropdown) still satisfies
     * this direction while hiding a value the verifier honours.
     */
    public function testEverySelectableThresholdIsOneTheVerifierAccepts(): void
    {
        $selectable = $this->selectableThresholds();
        $accepted = $this->thresholdsTheVerifierAccepts();

        $this->assertSame(
            [],
            array_values(array_diff($selectable, $accepted)),
            'Every option the admin form offers must be a value the verifier is willing to judge against. '
            . 'One that is not rejects every address the moment it is chosen: the merchant sees their whole '
            . 'import fail row by row, and nothing points at the setting they just changed.'
        );
        $this->assertNotSame(
            [],
            $selectable,
            'Fixture guard: the source model must offer something. An empty option list would satisfy the '
            . 'subset assertion above while leaving the merchant a select box they cannot choose anything '
            . 'in.'
        );
    }

    /**
     * ...and every threshold the verifier accepts must be selectable.
     *
     * The other direction, and not symmetric with the first: a value the verifier honours but the
     * form does not offer is a setting only whoever knows the config path can reach, which is the
     * state this ticket found the threshold in. Keeping the two lists equal is also what makes
     * the source model's list DERIVED from the verifier's rather than a copy of it.
     *
     * THIS IS THE HALF WITH TEETH, for the reason set out on the test above: both sides derive
     * from Validator::VALID_QUALITY_INDEXES, so a straight projection satisfies both directions
     * by construction - but a FILTERED derivation does not. A source model that dropped a grade
     * from the dropdown ("Poor" hidden as not recommended) would still offer only values the
     * verifier accepts, and only this direction would notice that a threshold the verifier
     * honours had become unreachable from the form.
     */
    public function testEveryThresholdTheVerifierAcceptsIsSelectable(): void
    {
        $selectable = $this->selectableThresholds();
        $accepted = $this->thresholdsTheVerifierAccepts();

        $this->assertSame(
            [],
            array_values(array_diff($accepted, $selectable)),
            'Every quality index the verifier accepts must be offered in the admin form. One that is not '
            . 'is a threshold only a data patch or a CLI call can select, which is precisely the situation '
            . 'LOQ-17148 exists to end - and it means the two lists have started to drift, so the next '
            . 'grade added to one of them will not reach the other either.'
        );
    }

    /**
     * The threshold etc/config.xml SHIPS must be one of the selectable options.
     *
     * The default is what a merchant runs until they change it, and it is what they see
     * pre-selected when they open the form. A default outside the option list shows the form
     * defaulting to something else entirely, so opening the page and saving it silently changes
     * the quality bar every import is judged against.
     */
    public function testTheShippedDefaultThresholdIsSelectable(): void
    {
        $defaults = self::xpathMatches($this->configXml(), sprintf(
            '/config/default/%s/%s/%s',
            self::SECTION_ID,
            self::GROUP_ID,
            self::FIELD_ID
        ));

        $this->assertCount(
            1,
            $defaults,
            'etc/config.xml must ship exactly one default for the threshold: with none, a fresh install '
            . 'reads it as null, which the verifier cannot judge against and which therefore rejects every '
            . 'address on the batch paths.'
        );
        $this->assertContains(
            trim((string)$defaults[0]),
            $this->selectableThresholds(),
            'The shipped default must be one of the options the admin form offers. If it is not, the form '
            . 'shows a different value pre-selected than the one in force, and merely opening the page and '
            . 'saving changes the quality bar every import row is judged against.'
        );
    }

    /**
     * Every option must carry a label a merchant can act on, and no two may read the same.
     *
     * A select of unlabelled or identically-labelled entries is a setting nobody can choose
     * deliberately, which is not much better than the unexposed field this ticket replaces.
     */
    public function testEverySelectableThresholdIsLabelledDistinctly(): void
    {
        $labels = [];
        foreach ((new AddressQualityIndex())->toOptionArray() as $option) {
            $labels[] = trim((string)($option['label'] ?? ''));
        }

        $this->assertNotContains(
            '',
            $labels,
            'Every quality index offered must have a label: the merchant chooses a quality bar, not a '
            . 'letter grade out of the Cleansing API\'s response format.'
        );
        $this->assertSame(
            $labels,
            array_values(array_unique($labels)),
            'Two options may not read the same. Duplicated labels make the choice a guess, and a merchant '
            . 'who guesses wrong gets either a bar that admits everything or one that admits nothing.'
        );
    }

    /**
     * The values the admin form offers, read from the source model the field is wired to.
     *
     * @return string[]
     */
    private function selectableThresholds(): array
    {
        $source = new AddressQualityIndex();
        $this->assertInstanceOf(
            OptionSourceInterface::class,
            $source,
            'The source model must be an option source, or Magento cannot render the select from it at all.'
        );

        $values = [];
        foreach ($source->toOptionArray() as $option) {
            $this->assertArrayHasKey(
                'value',
                (array)$option,
                'Every option must carry a value: an option without one is an entry the merchant can pick '
                . 'that saves nothing.'
            );
            $values[] = (string)$option['value'];
        }

        return $values;
    }

    /**
     * The thresholds the verifier is willing to judge an address against, read from
     * Validator's own list rather than restated, so the two sides of this comparison cannot be
     * kept in agreement by editing the test.
     *
     * @return string[]
     */
    private function thresholdsTheVerifierAccepts(): array
    {
        $reflection = new ReflectionClass(Validator::class);
        if (!$reflection->hasConstant('VALID_QUALITY_INDEXES')) {
            $this->fail(
                'Validator::VALID_QUALITY_INDEXES is not defined. It is the list checkQualityIndex() '
                . 'requires the configured threshold to be in; without it an unreadable threshold is judged '
                . 'by a bare string comparison, in which any text sorting above "E" passes every address.'
            );
        }

        $accepted = $reflection->getConstant('VALID_QUALITY_INDEXES');
        $this->assertIsArray($accepted, 'The verifier\'s accepted quality indexes must be a list.');

        return array_map(static fn ($value): string => (string)$value, $accepted);
    }

    /**
     * The shipped threshold field, as a list of matching elements.
     *
     * @return SimpleXMLElement[] Empty when the field is absent, which the callers report.
     */
    private function thresholdField(): array
    {
        return self::xpathMatches($this->systemXml(), sprintf(
            '/config/system/section[@id="%s"]/group[@id="%s"]/field[@id="%s"]',
            self::SECTION_ID,
            self::GROUP_ID,
            self::FIELD_ID
        ));
    }

    /**
     * Evaluate an xpath and answer a real list of elements.
     *
     * SimpleXMLElement::xpath() answers FALSE on a malformed expression, and `(array) false` is
     * `[false]` - a one-element array. So `assertCount(1, (array)$xml->xpath(...))` PASSES for a
     * query that never ran, which is the assertion this replaces at both of its call sites: a
     * typo'd expression, or a query against a document whose structure changed under it, read as
     * "exactly one match found". Normalising to [] here means the count assertions count
     * matches.
     *
     * @param SimpleXMLElement $xml Parsed document.
     * @param string $expression Xpath expression.
     * @return SimpleXMLElement[]
     */
    private static function xpathMatches(SimpleXMLElement $xml, string $expression): array
    {
        $matched = $xml->xpath($expression);

        return is_array($matched) ? $matched : [];
    }

    /** etc/adminhtml/system.xml, parsed. */
    private function systemXml(): SimpleXMLElement
    {
        return $this->xml(self::modulePath('etc/adminhtml/system.xml'));
    }

    /** etc/config.xml, parsed. */
    private function configXml(): SimpleXMLElement
    {
        return $this->xml(self::modulePath('etc/config.xml'));
    }

    /**
     * Parse one of the module's XML files, failing with the path rather than with a warning if
     * it is missing or malformed - a malformed one would break the admin form itself, so it is
     * worth its own message.
     *
     * THE libxml SUPPRESSION IS WHAT MAKES THE fail() BELOW REACHABLE. simplexml_load_string()
     * reports a parse error by RAISING E_WARNING as well as by returning false, and this suite
     * runs with failOnWarning="true" - so on a malformed file PHPUnit would fail the test on the
     * warning before the false was ever examined, and the message explaining that Magento cannot
     * read the file either would never be printed. libxml_use_internal_errors() diverts the
     * warning into libxml's own buffer, which this then clears and does not read: the message
     * below is the diagnostic worth having, and leaving the buffer dirty would leak parse errors
     * into whatever ran next. The previous state is restored rather than assumed, because it is
     * process-global.
     *
     * @param string $path Absolute path to the file.
     * @return SimpleXMLElement
     */
    private function xml(string $path): SimpleXMLElement
    {
        $this->assertFileExists($path, sprintf('%s is part of the module\'s shipped configuration.', $path));

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string)file_get_contents($path));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $this->fail(sprintf('%s is not parseable XML, so Magento cannot read it either.', $path));
        }

        return $xml;
    }

    /**
     * Absolute path of a file inside the module, from this test's location: Test/Unit/Model/
     * Config/Source is five levels below the module root.
     *
     * @param string $relative Path relative to the module root.
     * @return string
     */
    private static function modulePath(string $relative): string
    {
        return dirname(__DIR__, 5) . '/' . $relative;
    }
}
