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
	public const MAX_BODY_BYTES = 65536;

	/**
	 * Answers questions about the given state.
	 *
	 * @since 1.2.0
	 *
	 * @param string|array<mixed>                                                     $state     The content to judge: a
	 *                                                                                           non-empty string or array.
	 * @param array<string, \rtCamp\AIProviderForLMStudio\Decisions\DecisionQuestion> $questions Between 1 and 64 questions,
	 *                                                                                              keyed by question name.
	 * @param string|null                                                             $protocol  Optional. 'systemone' or
	 *                                                                                           'decisions'. Defaults to
	 *                                                                                           plugin setting.
	 * @return \rtCamp\AIProviderForLMStudio\Decisions\DecisionResult The answers.
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException If the state or questions are invalid.
	 */
	public function decide( $state, array $questions, ?string $protocol = null ): DecisionResult {
		$effective_protocol = $this->resolveProtocol( $protocol );
		$endpoint           = self::PROTOCOL_DECISIONS === $effective_protocol ? 'v1/decisions' : 'v1/systemone';

		$params  = $this->prepareDecideParams( $state, $questions, $effective_protocol );
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

		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = Plugin::with_lmstudio_http_context(
			function () use ( $request ): Response {
				return $this->getHttpTransporter()->send( $request );
			}
		);

		ResponseUtil::throwIfNotSuccessful( $response );

		return $this->parseResponseToDecisionResult( $response, $questions );
	}

	/**
	 * Resolves the decision protocol to use.
	 *
	 * Priority:
	 * 1. Explicitly passed parameter to decide().
	 * 2. ModelConfig custom option 'decision_protocol'.
	 * 3. Global plugin setting.
	 *
	 * @since 1.2.0
	 *
	 * @param string|null $protocol The optional protocol argument.
	 * @return string The resolved protocol ('systemone' or 'decisions').
	 */
	private function resolveProtocol( ?string $protocol ): string {
		if ( null !== $protocol && in_array( $protocol, [ self::PROTOCOL_SYSTEMONE, self::PROTOCOL_DECISIONS ], true ) ) {
			return $protocol;
		}

		$custom_options = $this->getConfig()->getCustomOptions();
		if ( isset( $custom_options['decision_protocol'] ) && is_string( $custom_options['decision_protocol'] ) ) {
			$opt = $custom_options['decision_protocol'];
			if ( in_array( $opt, [ self::PROTOCOL_SYSTEMONE, self::PROTOCOL_DECISIONS ], true ) ) {
				return $opt;
			}
		}

		return LMStudioSettings::get_decision_protocol();
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

		if ( ! isset( $response_data['answers'] ) || ! is_array( $response_data['answers'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'answers' );
		}

		$answers = [];
		foreach ( $questions as $name => $question ) {
			if ( ! array_key_exists( $name, $response_data['answers'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'answers.' . $name );
			}

			$answers[ $name ] = DecisionAnswer::fromResponseData(
				$question->getType(),
				$response_data['answers'][ $name ],
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
}
