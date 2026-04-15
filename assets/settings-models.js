( function() {
	'use strict';

	const ERROR_COLOR = '#d63638';
	const STATUS_COLOR = '#50575e';

	/** @type {Object.<string, {reasoning: {allowed_options: string[], default: string}|null}>} */
	let modelCapabilitiesMap = {};
	/** @type {Object.<string, string>} */
	let reasoningByModel = {};

	function syncReasoningHiddenInputs() {
		const container = document.getElementById( 'lmstudio-reasoning-hidden-inputs' );
		if ( ! container ) {
			return;
		}

		container.innerHTML = '';

		Object.keys( reasoningByModel ).forEach( function( modelId ) {
			const value = reasoningByModel[ modelId ];
			if ( ! modelId || ! value ) {
				return;
			}

			const input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = 'connector_for_lmstudio_settings[reasoning][' + modelId + ']';
			input.value = value;
			container.appendChild( input );
		} );
	}

	function getModelId( model ) {
		if ( model && typeof model.id === 'string' && model.id ) {
			return model.id;
		}

		if ( model && typeof model.name === 'string' && model.name ) {
			return model.name;
		}

		return '';
	}

	function getSelectedModelId() {
		const select = document.getElementById( 'connector_for_lmstudio_settings-model' );
		return select ? select.value : '';
	}

	/**
	 * Renders reasoning radio buttons for the currently selected model.
	 *
	 * @param {string} savedReasoning Previously saved reasoning value (used on initial page load), Pass '' to fall back to the model's own default.
	 */
	function renderReasoning( savedReasoning ) {
		const container = document.getElementById( 'lmstudio-reasoning-container' );
		const fieldset = document.getElementById( 'lmstudio-reasoning-fieldset' );

		if ( ! container || ! fieldset ) {
			return;
		}

		// Remove any previously rendered radio labels (keep the <legend>).
		Array.from( fieldset.querySelectorAll( 'label' ) ).forEach( function( el ) {
			el.remove();
		} );

		const modelId = getSelectedModelId();
		const capabilities = modelId ? modelCapabilitiesMap[ modelId ] : null;
		const reasoning = capabilities && capabilities.reasoning ? capabilities.reasoning : null;

		if (
			! reasoning ||
			! Array.isArray( reasoning.allowed_options ) ||
			reasoning.allowed_options.length === 0
		) {
			container.style.display = 'none';
			return;
		}

		// Determine which option to pre-select.
		const options = reasoning.allowed_options;
		const modelDefault = reasoning.default || options[ 0 ];
		const currentSavedForModel = reasoningByModel[ modelId ] || '';
		const effectiveValue =
			currentSavedForModel && options.indexOf( currentSavedForModel ) !== -1
				? currentSavedForModel
				: ( savedReasoning && options.indexOf( savedReasoning ) !== -1 ? savedReasoning : modelDefault );

		reasoningByModel[ modelId ] = effectiveValue;
		syncReasoningHiddenInputs();

		options.forEach( function( option ) {
			const label = document.createElement( 'label' );
			label.style.cssText = 'display:inline-flex;align-items:center;gap:0.3rem;margin-right:1rem !important;';

			const radio = document.createElement( 'input' );
			radio.type = 'radio';
			radio.name = 'lmstudio-reasoning-ui';
			radio.id = 'lmstudio-reasoning-' + option;
			radio.value = option;
			radio.checked = ( option === effectiveValue );
			label.htmlFor = radio.id;

			radio.addEventListener( 'change', function() {
				reasoningByModel[ modelId ] = option;
				syncReasoningHiddenInputs();
			} );

			const capitalised = option.charAt( 0 ).toUpperCase() + option.slice( 1 );
			label.appendChild( radio );
			label.appendChild( document.createTextNode( capitalised ) );
			fieldset.appendChild( label );
		} );

		container.style.display = 'block';
	}

	function renderModels( models, selectedModel ) {
		const select = document.getElementById( 'connector_for_lmstudio_settings-model' );
		const status = document.getElementById( 'lmstudio-model-status' );

		if ( ! select || ! status ) {
			return;
		}

		select.innerHTML = '';

		const defaultOption = document.createElement( 'option' );
		defaultOption.value = '';
		defaultOption.textContent = 'Use model selected by AI Client';
		select.appendChild( defaultOption );

		let hasSelectedModel = false;

		if ( models.length === 0 ) {
			status.textContent = 'No models found. Load or download a model in LM Studio and reload this page.';
			status.style.color = ERROR_COLOR;

			if ( selectedModel ) {
				const selectedOnlyOption = document.createElement( 'option' );
				selectedOnlyOption.value = selectedModel;
				selectedOnlyOption.textContent = selectedModel + ' (saved)';
				selectedOnlyOption.selected = true;
				select.appendChild( selectedOnlyOption );
			}

			return;
		}

		models.forEach( function( model ) {
			const modelId = getModelId( model );
			if ( ! modelId ) {
				return;
			}

			const option = document.createElement( 'option' );
			option.value = modelId;
			option.textContent = modelId;

			if ( selectedModel && modelId === selectedModel ) {
				option.selected = true;
				hasSelectedModel = true;
			}

			select.appendChild( option );
		} );

		if ( selectedModel && ! hasSelectedModel ) {
			const missingOption = document.createElement( 'option' );
			missingOption.value = selectedModel;
			missingOption.textContent = selectedModel + ' (saved)';
			missingOption.selected = true;
			select.appendChild( missingOption );
		}

		status.textContent = models.length === 1 ? '1 model loaded from server.' : models.length + ' models loaded from server.';
		status.style.color = STATUS_COLOR;

		// Re-render reasoning whenever a different model is chosen.
		select.addEventListener( 'change', function() {
			renderReasoning( '' );
		} );
	}

	function renderError( message ) {
		const status = document.getElementById( 'lmstudio-model-status' );
		if ( ! status ) {
			return;
		}

		status.textContent = message;
		status.style.color = ERROR_COLOR;
	}

	function loadModels( ajaxUrl, selectedModel ) {
		const status = document.getElementById( 'lmstudio-model-status' );
		if ( ! status ) {
			return;
		}

		status.textContent = 'Loading models...';

		window
			.fetch( ajaxUrl, {
				credentials: 'same-origin',
			} )
			.then( function( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Could not connect to load models.' );
				}
				return response.json();
			} )
			.then( function( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( payload && typeof payload.data === 'string' ? payload.data : 'Failed to load models.' );
				}

				const models = Array.isArray( payload.data ) ? payload.data : [];
				renderModels( models, selectedModel );
			} )
			.catch( function( error ) {
				renderError( error && error.message ? error.message : 'Failed to load models.' );
			} );
	}

	/**
	 * Fetches per-model reasoning capabilities and, once available, renders the
	 * reasoning radio buttons for the currently selected model.
	 *
	 * @param {string} capabilitiesAjaxUrl URL for the capabilities AJAX action.
	 * @param {string} savedReasoning      Previously saved reasoning setting value.
	 */
	function loadCapabilities( capabilitiesAjaxUrl, savedReasoning ) {
		window
			.fetch( capabilitiesAjaxUrl, {
				credentials: 'same-origin',
			} )
			.then( function( response ) {
				if ( ! response.ok ) {
					return null;
				}
				return response.json();
			} )
			.then( function( payload ) {
				if ( ! payload || ! payload.success || typeof payload.data !== 'object' ) {
					return;
				}
				modelCapabilitiesMap = payload.data || {};
				renderReasoning( savedReasoning );
			} )
			.catch( function() {
				// Fail silently – reasoning UI simply stays hidden.
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function() {
		if ( ! window.ConnectorForLMStudioSettings || ! window.ConnectorForLMStudioSettings.ajaxUrl ) {
			return;
		}

		const settings = window.ConnectorForLMStudioSettings;
		reasoningByModel = settings.selectedReasoningByModel && typeof settings.selectedReasoningByModel === 'object'
			? settings.selectedReasoningByModel
			: {};
		syncReasoningHiddenInputs();

		loadModels(
			settings.ajaxUrl,
			settings.selectedModel || '',
		);

		if ( settings.capabilitiesAjaxUrl ) {
			loadCapabilities(
				settings.capabilitiesAjaxUrl,
				settings.selectedReasoning || '',
			);
		}
	} );
}() );
