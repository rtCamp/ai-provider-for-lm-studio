<?php
/**
 * LM Studio decision model.
 *
 * @package rtCamp\AIProviderForLMStudio\Models
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace rtCamp\AIProviderForLMStudio\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Results\DTO\TokenUsage;
use rtCamp\AIProviderForLMStudio\Decisions\DecisionAnswer;
use rtCamp\AIProviderForLMStudio\Decisions\DecisionQuestion;
use rtCamp\AIProviderForLMStudio\Decisions\DecisionResult;
use rtCamp\AIProviderForLMStudio\Plugin;
use rtCamp\AIProviderForLMStudio\Provider\LMStudioProvider;
use rtCamp\AIProviderForLMStudio\Settings\LMStudioSettings;

/**
 * Class for an LM Studio decision model.
 *
 * Supports both LM Studio System One (/v1/systemone) and OpenAI-compatible (/v1/decisions)
 * decision endpoints.
 *
 * @since 1.2.0
 *
 * @phpstan-type ResponseData array{
 *     model?: string,
 *     answers?: array<string, mixed>,
 *     questions?: list<array<string, mixed>>,
 *     output?: list<array<string, mixed>>,
 *     usage?: array{input_tokens?: int, output_tokens?: int}
 * }
 */
class LMStudioDecisionModel extends AbstractApiBasedModel {

	/**
	 * Protocol identifier for System One / Jev compatibility.
	 */
	public const PROTOCOL_SYSTEMONE = 'systemone';

	/**
	 * Protocol identifier for OpenAI-compatible decisions.
	 */
	public const PROTOCOL_DECISIONS = 'decisions';

	/**
	 * Protocol identifier for Chat Completions fallback (for multimodal/vision inputs).
	 */
	public const PROTOCOL_CHAT = 'chat';

	/**
	 * The maximum number of questions in one request.
	 *
	 * @since 1.2.0
	 *
	 * @var int
	 */
	public const MAX_QUESTIONS = 64;

	/**
	 * The maximum request body size accepted, in bytes.
	 *
	 * @since 1.2.0
	 *
	 * @var int
	 */
	public const MAX_BODY_BYTES = 20971520;

	/**
	 * In-memory cache for resolved working decision endpoint per host URL.
	 *
	 * @var array<string, array{endpoint: string, protocol: string}>
	 */
	private static array $resolved_endpoint_cache = [];

	/**
	 * Answers questions about the given state.
	 *
	 * Automatically supports both System One (/v1/systemone) and OpenAI-compatible
	 * (/v1/decisions, /v1/decision) endpoints.
	 *
	 * @since 1.2.0
	 *
	 * @param string|array<mixed>                                                     $state     The content to judge: a
	 *                                                                                           non-empty string or array.
	 * @param array<string, \rtCamp\AIProviderForLMStudio\Decisions\DecisionQuestion> $questions Between 1 and 64 questions,
	 *                                                                                              keyed by question name.
	 * @param string|null                                                             $protocol  Optional. Explicit protocol ('systemone',
	 *                                                                                           'decisions', or 'decision'). Defaults to null
	 *                                                                                           (automatic endpoint negotiation).
	 * @return \rtCamp\AIProviderForLMStudio\Decisions\DecisionResult The answers.
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the request fails or response is invalid.
	 */
	public function decide( $state, array $questions, ?string $protocol = null ): DecisionResult {
		if ( $this->isMultimodalState( $state ) ) {
			return $this->decideViaChatCompletions( $state, $questions );
		}

		$host_key = LMStudioProvider::url( '' );

		$explicit = $this->resolveExplicitProtocol( $protocol );
		if ( null !== $explicit ) {
			if ( self::PROTOCOL_CHAT === $explicit['protocol'] ) {
				return $this->decideViaChatCompletions( $state, $questions );
			}

			$response = $this->sendDecisionRequest( $explicit['endpoint'], $explicit['protocol'], $state, $questions );
			ResponseUtil::throwIfNotSuccessful( $response );

			return $this->parseResponseToDecisionResult( $response, $questions );
		}

		// Use cached working endpoint for this host if previously determined.
		if ( isset( self::$resolved_endpoint_cache[ $host_key ] ) ) {
			$cached   = self::$resolved_endpoint_cache[ $host_key ];
			$response = $this->sendDecisionRequest( $cached['endpoint'], $cached['protocol'], $state, $questions );
			$status   = $response->getStatusCode();

			if ( 404 !== $status && 405 !== $status ) {
				ResponseUtil::throwIfNotSuccessful( $response );

				return $this->parseResponseToDecisionResult( $response, $questions );
			}

			unset( self::$resolved_endpoint_cache[ $host_key ] );
		}

		// Automatic protocol resolution: try System One first, then Decisions.
		$candidates = [
			[
				'endpoint' => 'v1/systemone',
				'protocol' => self::PROTOCOL_SYSTEMONE,
			],
			[
				'endpoint' => 'v1/decisions',
				'protocol' => self::PROTOCOL_DECISIONS,
			],
			[
				'endpoint' => 'v1/decision',
				'protocol' => self::PROTOCOL_DECISIONS,
			],
		];

		$last_response = null;
		foreach ( $candidates as $candidate ) {
			$response = $this->sendDecisionRequest( $candidate['endpoint'], $candidate['protocol'], $state, $questions );
			$status   = $response->getStatusCode();

			if ( 404 === $status || 405 === $status ) {
				$last_response = $response;
				continue;
			}

			ResponseUtil::throwIfNotSuccessful( $response );

			self::$resolved_endpoint_cache[ $host_key ] = $candidate;

			return $this->parseResponseToDecisionResult( $response, $questions );
		}

		ResponseUtil::throwIfNotSuccessful( $last_response );

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new \WordPress\AiClient\Providers\Http\Exception\ResponseException( 'No supported decision endpoint found on LM Studio host.' );
	}

	/**
	 * Sends a single decision request to a specific endpoint.
	 *
	 * @since 1.2.0
	 *
	 * @param string                                                                  $endpoint  The API endpoint relative to base URL.
	 * @param string                                                                  $protocol  The protocol format ('systemone' or 'decisions').
	 * @param string|array<mixed>                                                     $state     The content to judge.
	 * @param array<string, \rtCamp\AIProviderForLMStudio\Decisions\DecisionQuestion> $questions The questions.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response The API response.
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException If the request payload exceeds max size.
	 */
	private function sendDecisionRequest( string $endpoint, string $protocol, $state, array $questions ): Response {
		$params  = $this->prepareDecideParams( $state, $questions, $protocol );
		$request = new Request(
			HttpMethodEnum::POST(),
			LMStudioProvider::url( $endpoint ),
			[ 'Content-Type' => 'application/json' ],
			$params,
			$this->prepareRequestOptions()
		);

		$body_bytes = strlen( (string) $request->getBody() );
		if ( $body_bytes > self::MAX_BODY_BYTES ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException(
				sprintf(
					'The decision request is %1$d bytes; LM Studio accepts at most %2$d.',
					$body_bytes,
					self::MAX_BODY_BYTES
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$request = $this->getRequestAuthentication()->authenticateRequest( $request );

		return Plugin::with_lmstudio_http_context(
			function () use ( $request ): Response {
				return $this->getHttpTransporter()->send( $request );
			}
		);
	}

	/**
	 * Resolves an explicitly requested decision protocol.
	 *
	 * Priority:
	 * 1. Explicitly passed parameter to decide().
	 * 2. ModelConfig custom option 'decision_protocol'.
	 *
	 * @since 1.2.0
	 *
	 * @param string|null $protocol The optional protocol argument.
	 * @return array{endpoint: string, protocol: string}|null The resolved endpoint and protocol, or null for automatic mode.
	 */
	private function resolveExplicitProtocol( ?string $protocol ): ?array {
		$target = null;

		if ( null !== $protocol && '' !== trim( $protocol ) ) {
			$target = trim( $protocol );
		} else {
			$custom_options = $this->getConfig()->getCustomOptions();
			if ( isset( $custom_options['decision_protocol'] ) && is_string( $custom_options['decision_protocol'] ) ) {
				$target = trim( $custom_options['decision_protocol'] );
			}
		}

		if ( null === $target ) {
			return null;
		}

		if ( self::PROTOCOL_SYSTEMONE === $target || 'systemone' === $target ) {
			return [
				'endpoint' => 'v1/systemone',
				'protocol' => self::PROTOCOL_SYSTEMONE,
			];
		}

		if ( 'decision' === $target ) {
			return [
				'endpoint' => 'v1/decision',
				'protocol' => self::PROTOCOL_DECISIONS,
			];
		}

		if ( self::PROTOCOL_DECISIONS === $target || 'decisions' === $target ) {
			return [
				'endpoint' => 'v1/decisions',
				'protocol' => self::PROTOCOL_DECISIONS,
			];
		}

		if ( self::PROTOCOL_CHAT === $target || 'chat' === $target || 'chat_completions' === $target ) {
			return [
				'endpoint' => 'v1/chat/completions',
				'protocol' => self::PROTOCOL_CHAT,
			];
		}

		return null;
	}

	/**
	 * Prepares the state, questions, and model configuration into API request parameters.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed                                                                   $state     The content to judge.
	 * @param array<string, \rtCamp\AIProviderForLMStudio\Decisions\DecisionQuestion> $questions The questions, keyed by name.
	 * @param string                                                                  $protocol  The resolved protocol.
	 * @return array<string, mixed> The parameters for the API request.
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException If the state or questions are invalid.
	 */
	private function prepareDecideParams( $state, array $questions, string $protocol ): array {
		if ( is_string( $state ) ? '' === trim( $state ) : ( ! is_array( $state ) || [] === $state ) ) {
			throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'Decision state must be a non-empty string or array.' );
		}

		$question_count = count( $questions );
		if ( 0 === $question_count || $question_count > self::MAX_QUESTIONS ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException(
				sprintf( 'A decision request needs between 1 and %1$d questions, %2$d given.', self::MAX_QUESTIONS, $question_count )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		foreach ( $questions as $name => $question ) {
			if ( ! is_string( $name ) || '' === $name ) {
				throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'Decision questions must be keyed by non-empty string names.' );
			}

			if ( ! $question instanceof DecisionQuestion ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException(
					sprintf( 'The decision question "%s" must be a DecisionQuestion instance.', $name )
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}

		$model_id                = $this->metadata()->getId();
		$selected_decision_model = LMStudioSettings::get_selected_decision_model();
		if ( '' !== $selected_decision_model ) {
			$model_id = $selected_decision_model;
		}

		if ( self::PROTOCOL_DECISIONS === $protocol ) {
			$questions_list = [];
			foreach ( $questions as $name => $question ) {
				$questions_list[] = $question->toOpenAIDecisionArray( $name );
			}

			$params = [
				'model'     => $model_id,
				'input'     => $state,
				'questions' => $questions_list,
			];
		} else {
			$questions_data = [];
			foreach ( $questions as $name => $question ) {
				$questions_data[ $name ] = $question->toSystemOneArray();
			}

			$params = [
				'model'     => $model_id,
				'state'     => $state,
				'questions' => $questions_data,
			];
		}

		$ignored_custom_options = [ 'decision_protocol', 'lmstudio.request_timeout', 'lmstudio.connect_timeout' ];

		foreach ( $this->getConfig()->getCustomOptions() as $key => $value ) {
			if ( in_array( $key, $ignored_custom_options, true ) ) {
				continue;
			}

			if ( isset( $params[ $key ] ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException(
					sprintf(
						'The custom option "%s" conflicts with an existing parameter.',
						$key
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			$params[ $key ] = $value;
		}

		return $params;
	}

	/**
	 * Parses an LM Studio decision response to a decision result.
	 *
	 * @since 1.2.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response                         $response  The LM Studio API response.
	 * @param array<string, \rtCamp\AIProviderForLMStudio\Decisions\DecisionQuestion> $questions The questions asked.
	 * @return \rtCamp\AIProviderForLMStudio\Decisions\DecisionResult The parsed result.
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If an answer is missing or malformed.
	 */
	private function parseResponseToDecisionResult( Response $response, array $questions ): DecisionResult {
		/** @var ResponseData $response_data */
		$response_data = $response->getData();
		$raw_answers   = $this->extractRawAnswers( $response_data );

		if ( ! is_array( $raw_answers ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'answers' );
		}

		$answers = [];
		foreach ( $questions as $name => $question ) {
			if ( ! array_key_exists( $name, $raw_answers ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'answers.' . $name );
			}

			$answers[ $name ] = DecisionAnswer::fromResponseData(
				$question->getType(),
				$raw_answers[ $name ],
				'answers.' . $name
			);
		}

		$usage         = isset( $response_data['usage'] ) && is_array( $response_data['usage'] ) ? $response_data['usage'] : [];
		$input_tokens  = isset( $usage['input_tokens'] ) && is_int( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0;
		$output_tokens = isset( $usage['output_tokens'] ) && is_int( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0;

		return new DecisionResult(
			isset( $response_data['model'] ) && is_string( $response_data['model'] ) ? $response_data['model'] : $this->metadata()->getId(),
			$answers,
			new TokenUsage( $input_tokens, $output_tokens, $input_tokens + $output_tokens )
		);
	}

	/**
	 * Extracts raw answers map from API response data.
	 *
	 * Supports answers object, questions array, or output array.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $response_data The response data.
	 * @return array<string, mixed>|null The extracted answers map, or null.
	 */
	private function extractRawAnswers( array $response_data ): ?array {
		if ( isset( $response_data['answers'] ) && is_array( $response_data['answers'] ) ) {
			return $response_data['answers'];
		}

		$list = null;
		if ( isset( $response_data['questions'] ) && is_array( $response_data['questions'] ) ) {
			$list = $response_data['questions'];
		} elseif ( isset( $response_data['output'] ) && is_array( $response_data['output'] ) ) {
			$list = $response_data['output'];
		}

		if ( null === $list ) {
			return null;
		}

		$answers = [];
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['name'] ) || ! is_string( $item['name'] ) ) {
				continue;
			}

			$answers[ $item['name'] ] = $item['answer'] ?? $item['decision'] ?? null;
		}

		return $answers;
	}

	/**
	 * Prepares request options for decisions.
	 *
	 * @since 1.2.0
	 *
	 * @return \WordPress\AiClient\Providers\Http\DTO\RequestOptions
	 */
	private function prepareRequestOptions(): RequestOptions {
		$existing_options = $this->getRequestOptions();
		$options_array    = null !== $existing_options ? $existing_options->toArray() : [];

		if ( ! isset( $options_array['timeout'] ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Local model warm-up can exceed the default HTTP timeout.
			$options_array['timeout'] = 60.0;
		}

		if ( ! isset( $options_array['connectTimeout'] ) ) {
			$options_array['connectTimeout'] = 10.0;
		}

		return RequestOptions::fromArray( $options_array );
	}

	/**
	 * Checks whether the state contains image data or image references.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $state The state to check.
	 * @return bool True if state contains an image.
	 */
	private function isMultimodalState( $state ): bool {
		if ( is_string( $state ) ) {
			$trimmed = trim( $state );
			if ( str_starts_with( $trimmed, 'data:image/' ) ) {
				return true;
			}

			return 1 === preg_match( '#^https?://\S+\.(jpe?g|png|gif|webp|bmp|svg)(\?.*)?$#i', $trimmed );
		}

		if ( is_array( $state ) ) {
			if ( isset( $state['images'] ) && is_array( $state['images'] ) && ! empty( $state['images'] ) ) {
				return true;
			}

			foreach ( $state as $item ) {
				if ( is_array( $item ) && ( ( isset( $item['type'] ) && 'image_url' === $item['type'] ) || isset( $item['image_url'] ) ) ) {
					return true;
				}
				if ( is_string( $item ) && $this->isMultimodalState( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Resolves the model ID to use for chat-completions decision evaluations.
	 *
	 * Prefers the selected general/vision model from settings if the decision model
	 * is a text-only classifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string The resolved model ID.
	 */
	private function resolveChatModelId(): string {
		$selected_general_model = LMStudioSettings::get_selected_model();
		if ( '' !== $selected_general_model && 'laya' !== $selected_general_model ) {
			return $selected_general_model;
		}

		$model_id = $this->metadata()->getId();
		if ( 'laya' !== $model_id && '' !== $model_id && 'default' !== $model_id ) {
			return $model_id;
		}

		if ( '' !== $selected_general_model ) {
			return $selected_general_model;
		}

		return 'default';
	}

	/**
	 * Evaluates decision questions via the /v1/chat/completions endpoint.
	 *
	 * Used for multimodal (image) inputs and as a fallback when native decision endpoints
	 * are unavailable or unsupported by the active model.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed                                                                   $state     The content to judge.
	 * @param array<string, \rtCamp\AIProviderForLMStudio\Decisions\DecisionQuestion> $questions The questions, keyed by name.
	 * @return \rtCamp\AIProviderForLMStudio\Decisions\DecisionResult The parsed result.
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the request fails or response cannot be parsed.
	 */
	private function decideViaChatCompletions( $state, array $questions ): DecisionResult {
		$model_id = $this->resolveChatModelId();

		$question_instructions = [];
		foreach ( $questions as $name => $question ) {
			$type = $question->getType();
			if ( $type->isNoul() ) {
				$question_instructions[] = sprintf( '- "%s" (boolean: true or false): %s', $name, $question->getInstructions() );
			} elseif ( $type->isChoice() ) {
				$criteria                = $question->getCriteria();
				$options                 = is_array( $criteria ) ? array_keys( $criteria ) : [];
				$question_instructions[] = sprintf(
					'- "%s" (choice from [%s]): %s',
					$name,
					implode( ', ', $options ),
					$question->getInstructions()
				);
			} elseif ( $type->isScore() ) {
				$question_instructions[] = sprintf( '- "%s" (number): %s', $name, $question->getInstructions() );
			}
		}

		$schema_examples = [];
		foreach ( array_keys( $questions ) as $name ) {
			$schema_examples[] = '    "' . $name . '": <answer>';
		}

		$prompt = "You are a decision model. Evaluate the input/image against the following typed questions and return ONLY a valid JSON object matching the requested schema.\n\n"
			. "Questions:\n" . implode( "\n", $question_instructions ) . "\n\n"
			. "Return ONLY a valid JSON object with an \"answers\" key containing the answer for each question:\n"
			. "{\n  \"answers\": {\n"
			. implode( ",\n", $schema_examples )
			. "\n  }\n}\n"
			. 'Do not include any explanation or markdown outside the JSON.';

		$content = [
			[
				'type' => 'text',
				'text' => $prompt,
			],
		];

		if ( is_string( $state ) ) {
			$trimmed = trim( $state );
			if ( str_starts_with( $trimmed, 'data:image/' ) || 1 === preg_match( '#^https?://#i', $trimmed ) ) {
				$content[] = [
					'type'      => 'image_url',
					'image_url' => [
						'url' => $trimmed,
					],
				];
			} else {
				$content[] = [
					'type' => 'text',
					'text' => $state,
				];
			}
		} elseif ( is_array( $state ) ) {
			if ( isset( $state['images'] ) && is_array( $state['images'] ) ) {
				foreach ( $state['images'] as $img_url ) {
					if ( ! is_string( $img_url ) ) {
						continue;
					}

					$content[] = [
						'type'      => 'image_url',
						'image_url' => [ 'url' => $img_url ],
					];
				}
			}
			if ( isset( $state['text'] ) && is_string( $state['text'] ) ) {
				$content[] = [
					'type' => 'text',
					'text' => $state['text'],
				];
			}
		}

		$payload = [
			'model'       => $model_id,
			'messages'    => [
				[
					'role'    => 'user',
					'content' => $content,
				],
			],
			'temperature' => 0.0,
		];

		$request = new Request(
			HttpMethodEnum::POST(),
			LMStudioProvider::url( 'v1/chat/completions' ),
			[ 'Content-Type' => 'application/json' ],
			$payload,
			$this->prepareRequestOptions()
		);

		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = Plugin::with_lmstudio_http_context(
			function () use ( $request ): Response {
				return $this->getHttpTransporter()->send( $request );
			}
		);

		ResponseUtil::throwIfNotSuccessful( $response );

		$response_data = $response->getData();
		$raw_content   = '';
		if ( isset( $response_data['choices'][0]['message']['content'] ) && is_string( $response_data['choices'][0]['message']['content'] ) ) {
			$raw_content = trim( $response_data['choices'][0]['message']['content'] );
		}

		// Strip markdown code fences if present.
		if ( 1 === preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/i', $raw_content, $matches ) ) {
			$raw_content = trim( $matches[1] );
		}

		$decoded = json_decode( $raw_content, true );
		if ( ! is_array( $decoded ) ) {
			$first_brace = strpos( $raw_content, '{' );
			$last_brace  = strrpos( $raw_content, '}' );
			if ( false !== $first_brace && false !== $last_brace && $last_brace > $first_brace ) {
				$json_candidate = substr( $raw_content, $first_brace, $last_brace - $first_brace + 1 );
				$decoded        = json_decode( $json_candidate, true );
			}
		}

		if ( ! is_array( $decoded ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \WordPress\AiClient\Providers\Http\Exception\ResponseException(
				'Failed to parse decision JSON from LM Studio chat response.'
			);
		}

		$raw_answers = isset( $decoded['answers'] ) && is_array( $decoded['answers'] ) ? $decoded['answers'] : $decoded;
		$answers     = [];
		foreach ( $questions as $name => $question ) {
			if ( ! array_key_exists( $name, $raw_answers ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromMissingData(
					$this->providerMetadata()->getName(),
					'answers.' . $name
				);
			}

			$answers[ $name ] = DecisionAnswer::fromResponseData(
				$question->getType(),
				$raw_answers[ $name ],
				'answers.' . $name
			);
		}

		$usage         = isset( $response_data['usage'] ) && is_array( $response_data['usage'] ) ? $response_data['usage'] : [];
		$input_tokens  = isset( $usage['prompt_tokens'] ) && is_int( $usage['prompt_tokens'] ) ? $usage['prompt_tokens'] : 0;
		$output_tokens = isset( $usage['completion_tokens'] ) && is_int( $usage['completion_tokens'] ) ? $usage['completion_tokens'] : 0;

		return new DecisionResult(
			$model_id,
			$answers,
			new TokenUsage( $input_tokens, $output_tokens, $input_tokens + $output_tokens )
		);
	}
}
