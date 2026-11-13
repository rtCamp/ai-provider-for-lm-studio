<?php
/**
 * Enum for decision question types.
 *
 * @package rtCamp\AIProviderForLMStudio\Decisions
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace rtCamp\AIProviderForLMStudio\Decisions;

use WordPress\AiClient\Common\AbstractEnum;

/**
 * Enum for the question types a decision model answers.
 *
 * @since 1.2.0
 *
 * @method static self choice() Creates an instance for the CHOICE type.
 * @method static self noul() Creates an instance for the NOUL type.
 * @method static self score() Creates an instance for the SCORE type.
 * @method bool isChoice() Checks if the type is CHOICE.
 * @method bool isNoul() Checks if the type is NOUL.
 * @method bool isScore() Checks if the type is SCORE.
 */
class DecisionTypeEnum extends AbstractEnum {

	/**
	 * Picks one option from a named set.
	 */
	public const CHOICE = 'choice';

	/**
	 * Estimates the probability that a statement is true.
	 */
	public const NOUL = 'noul';

	/**
	 * Rates against ordered levels.
	 */
	public const SCORE = 'score';
}
