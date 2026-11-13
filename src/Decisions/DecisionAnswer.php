<?php
/**
 * An answer from a decision model.
 *
 * @package rtCamp\AIProviderForLMStudio\Decisions
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace rtCamp\AIProviderForLMStudio\Decisions;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- camelCase, matching the AI Client DTOs.

/**
 * Class for the typed answer to a single decision question.
 *
 * Which getters return a value depends on the question type:
 *  - choice: getChoice(), getProbabilities(), getConfidence().
 *  - noul:   getProbability(). The probability is the confidence.
 *  - score:  getScore(), getProbabilities(), getConfidence(), getLegend().
 *
 * @since 1.2.0
 */
class DecisionAnswer {

	/**
	 * The question type.
	 *
	 * @since 1.2.0
	 *
	 * @var \rtCamp\AIProviderForLMStudio\Decisions\DecisionTypeEnum
	 */
	private DecisionTypeEnum $type;

	/**
	 * The answer value: the option key for choice, a probability for noul, a position for score.
	 *
	 * @since 1.2.0
	 *
	 * @var string|float
	 */
	private $value;

	/**
	 * The probability of each option or level.
	 *
	 * @since 1.2.0
	 *
	 * @var array<string, float>
	 */
	private array $probabilities;

	/**
	 * How concentrated the distribution is, from 0 to 1, or null for noul answers.
	 *
	 * @since 1.2.0
	 *
	 * @var float|null
	 */
	private ?float $confidence;

	/**
	 * The score level descriptions, keyed by level index.
	 *
	 * @since 1.2.0
	 *
	 * @var array<string, string>
	 */
	private array $legend;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param \rtCamp\AIProviderForLMStudio\Decisions\DecisionTypeEnum $type          The question type.
	 * @param string|float                                             $value         The answer value.
	 * @param array<string, float>                                     $probabilities The probability of each option or level.
	 * @param float|null                                               $confidence    The confidence, or null for noul answers.
	 * @param array<string, string>                                    $legend        The score level descriptions.
	 */
	public function __construct(
		DecisionTypeEnum $type,
		$value,
		array $probabilities = [],
		?float $confidence = null,
		array $legend = []
	) {
		$this->type          = $type;
		$this->value         = $value;
		$this->probabilities = $probabilities;
		$this->confidence    = $confidence;
		$this->legend        = $legend;
	}

	/**
	 * Creates an answer from the API response data for one question.
	 *
	 * Supports both LM Studio System One (/v1/systemone) and OpenAI-compatible (/v1/decisions) structures.
	 *
	 * @since 1.2.0
	 *
	 * @param \rtCamp\AIProviderForLMStudio\Decisions\DecisionTypeEnum $type The type of the question asked.
	 * @param mixed                                                    $data The answer data from the response.
	 * @param string                                                   $path Where the answer sits in the response, for error messages.
	 * @return self The answer.
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the data does not match the type.
	 */
	public static function fromResponseData( DecisionTypeEnum $type, $data, string $path ): self {
		if ( $type->isNoul() ) {
			// Handle boolean from OpenAI Decisions API (e.g. true / false).
			if ( is_bool( $data ) ) {
				$prob = $data ? 1.0 : 0.0;
				return new self(
					$type,
					$prob,
					[
						'true'  => $prob,
						'false' => 1.0 - $prob,
					],
					1.0
				);
			}

			// Handle direct numeric probability.
			if ( is_numeric( $data ) ) {
				$prob = (float) $data;
				return new self( $type, $prob );
			}

			// Handle System One object structure.
			if ( is_array( $data ) ) {
				$key = isset( $data['probability'] ) ? 'probability' : ( isset( $data['noul'] ) ? 'noul' : null );
				if ( null !== $key ) {
					return new self( $type, self::readProbability( $data, $key, $path ) );
				}
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromInvalidData( 'LM Studio', $path, 'The value must be a boolean, number, or object with probability.' );
		}

		if ( $type->isChoice() ) {
			// Handle string from OpenAI Decisions API (e.g. "billing").
			if ( is_string( $data ) ) {
				return new self( $type, $data, [ $data => 1.0 ], 1.0 );
			}

			if ( ! is_array( $data ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromInvalidData( 'LM Studio', $path, 'The value must be a string or object.' );
			}

			if ( ! isset( $data['choice'] ) || ! is_string( $data['choice'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromInvalidData( 'LM Studio', $path . '.choice', 'The value must be a string.' );
			}

			$confidence    = isset( $data['confidence'] ) ? self::readProbability( $data, 'confidence', $path ) : 1.0;
			$probabilities = self::readProbabilities( $data );

			return new self( $type, $data['choice'], $probabilities, $confidence );
		}

		// SCORE type.
		if ( is_numeric( $data ) ) {
			return new self( $type, (float) $data );
		}

		if ( ! is_array( $data ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromInvalidData( 'LM Studio', $path, 'The value must be a number or object.' );
		}

		if ( ! isset( $data['score'] ) || ! is_numeric( $data['score'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromInvalidData( 'LM Studio', $path . '.score', 'The value must be a number.' );
		}

		$confidence    = isset( $data['confidence'] ) ? self::readProbability( $data, 'confidence', $path ) : null;
		$probabilities = self::readProbabilities( $data );

		$legend = [];
		if ( isset( $data['legend'] ) && is_array( $data['legend'] ) ) {
			foreach ( $data['legend'] as $level => $description ) {
				if ( ! is_string( $description ) ) {
					continue;
				}

				$legend[ (string) $level ] = $description;
			}
		}

		return new self( $type, (float) $data['score'], $probabilities, $confidence, $legend );
	}

	/**
	 * Returns the question type.
	 *
	 * @since 1.2.0
	 *
	 * @return \rtCamp\AIProviderForLMStudio\Decisions\DecisionTypeEnum The question type.
	 */
	public function getType(): DecisionTypeEnum {
		return $this->type;
	}

	/**
	 * Returns the raw answer value.
	 *
	 * @since 1.2.0
	 *
	 * @return string|float The option key for choice, a probability for noul, a position for score.
	 */
	public function getValue() {
		return $this->value;
	}

	/**
	 * Returns the most probable option of a choice answer.
	 *
	 * @since 1.2.0
	 *
	 * @return string|null The option key, or null if this is not a choice answer.
	 */
	public function getChoice(): ?string {
		return $this->type->isChoice() ? (string) $this->value : null;
	}

	/**
	 * Returns the probability that the statement of a noul answer is true.
	 *
	 * @since 1.2.0
	 *
	 * @return float|null The probability from 0 to 1, or null if this is not a noul answer.
	 */
	public function getProbability(): ?float {
		return $this->type->isNoul() ? (float) $this->value : null;
	}

	/**
	 * Returns the probability-weighted position of a score answer.
	 *
	 * @since 1.2.0
	 *
	 * @return float|null The position from 0 to one less than the number of levels, or null if
	 *                    this is not a score answer.
	 */
	public function getScore(): ?float {
		return $this->type->isScore() ? (float) $this->value : null;
	}

	/**
	 * Returns whether the answer evaluates to true (probability >= 0.5 or truthy value).
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if positive, false otherwise.
	 */
	public function asBoolean(): bool {
		$prob = $this->getProbability();
		if ( null !== $prob ) {
			return $prob >= 0.5;
		}

		return (bool) $this->value;
	}

	/**
	 * Returns the answer as a string.
	 *
	 * @since 1.2.0
	 *
	 * @return string The choice or string representation of the value.
	 */
	public function asString(): string {
		$choice = $this->getChoice();
		if ( null !== $choice ) {
			return $choice;
		}

		return is_scalar( $this->value ) ? (string) $this->value : '';
	}

	/**
	 * Returns the answer as a float number.
	 *
	 * @since 1.2.0
	 *
	 * @return float|null The score, probability, or numeric value.
	 */
	public function asNumber(): ?float {
		$score = $this->getScore();
		if ( null !== $score ) {
			return $score;
		}

		$prob = $this->getProbability();
		if ( null !== $prob ) {
			return $prob;
		}

		return is_numeric( $this->value ) ? (float) $this->value : null;
	}

	/**
	 * Returns the probability of each option (choice) or level index (score).
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, float> The probabilities, empty for noul answers.
	 */
	public function getProbabilities(): array {
		return $this->probabilities;
	}

	/**
	 * Returns how concentrated the distribution of a choice or score answer is.
	 *
	 * @since 1.2.0
	 *
	 * @return float|null The confidence from 0 to 1, or null for noul answers.
	 */
	public function getConfidence(): ?float {
		return $this->confidence;
	}

	/**
	 * Returns the level descriptions of a score answer, keyed by level index.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string> The legend, empty for other answers.
	 */
	public function getLegend(): array {
		return $this->legend;
	}

	/**
	 * Checks whether the answer is confident enough to act on.
	 *
	 * @since 1.2.0
	 *
	 * @param float $threshold The minimum confidence, from 0 to 1.
	 * @return bool True if the answer meets the threshold.
	 */
	public function isConfident( float $threshold ): bool {
		if ( $this->type->isNoul() ) {
			return abs( (float) $this->value - 0.5 ) * 2 >= $threshold;
		}

		return null !== $this->confidence && $this->confidence >= $threshold;
	}

	/**
	 * Returns the answer as an array, in the shape of the API response.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, mixed> The answer data.
	 */
	public function toArray(): array {
		$data = [
			'type'             => $this->type->value,
			$this->type->value => $this->value,
		];

		if ( $this->type->isNoul() ) {
			return $data;
		}

		if ( $this->type->isScore() ) {
			$data['legend'] = $this->legend;
		}

		$data['probabilities'] = $this->probabilities;
		$data['confidence']    = $this->confidence;

		return $data;
	}

	/**
	 * Reads a number between 0 and 1 from answer data.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $data The answer data.
	 * @param string       $key  The key to read.
	 * @param string       $path Where the answer sits in the response, for error messages.
	 * @return float The number.
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the value is missing or out of range.
	 */
	private static function readProbability( array $data, string $key, string $path ): float {
		if ( ! isset( $data[ $key ] ) || ! is_numeric( $data[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromInvalidData( 'LM Studio', $path . '.' . $key, 'The value must be a number.' );
		}

		$value = (float) $data[ $key ];
		if ( $value < 0.0 || $value > 1.0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromInvalidData( 'LM Studio', $path . '.' . $key, 'The value must be between 0 and 1.' );
		}

		return $value;
	}

	/**
	 * Reads the probability distribution from answer data.
	 *
	 * Supports both 'probabilities' and 'distribution' keys.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $data The answer data.
	 * @return array<string, float> The probabilities, keyed by option or level index.
	 */
	private static function readProbabilities( array $data ): array {
		$dist = $data['probabilities'] ?? ( $data['distribution'] ?? null );
		if ( ! is_array( $dist ) ) {
			return [];
		}

		$probabilities = [];
		foreach ( $dist as $key => $probability ) {
			if ( ! is_numeric( $probability ) ) {
				continue;
			}

			$probabilities[ (string) $key ] = (float) $probability;
		}

		return $probabilities;
	}
}
