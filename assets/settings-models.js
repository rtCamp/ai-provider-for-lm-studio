( function () {
	'use strict';

	var ERROR_COLOR = '#d63638';
	var STATUS_COLOR = '#50575e';

	function getModelId( model ) {
		if ( model && typeof model.id === 'string' && model.id ) {
			return model.id;
		}

		if ( model && typeof model.name === 'string' && model.name ) {
			return model.name;
		}

		return '';
	}

	function renderModels( models, selectedModel ) {
		var select = document.getElementById( 'ai_provider_for_lmstudio_settings-model' );
		var status = document.getElementById( 'lmstudio-model-status' );

		if ( ! select || ! status ) {
			return;
		}

		select.innerHTML = '';

		var defaultOption = document.createElement( 'option' );
		defaultOption.value = '';
		defaultOption.textContent = 'Use model selected by AI Client';
		select.appendChild( defaultOption );

		var hasSelectedModel = false;

		if ( models.length === 0 ) {
			status.textContent = 'No models found. Load or download a model in LM Studio and reload this page.';
			status.style.color = ERROR_COLOR;

			if ( selectedModel ) {
				var selectedOnlyOption = document.createElement( 'option' );
				selectedOnlyOption.value = selectedModel;
				selectedOnlyOption.textContent = selectedModel + ' (saved)';
				selectedOnlyOption.selected = true;
				select.appendChild( selectedOnlyOption );
			}

			return;
		}

		models.forEach( function ( model ) {
			var modelId = getModelId( model );
			if ( ! modelId ) {
				return;
			}

			var option = document.createElement( 'option' );
			option.value = modelId;
			option.textContent = modelId;

			if ( selectedModel && modelId === selectedModel ) {
				option.selected = true;
				hasSelectedModel = true;
			}

			select.appendChild( option );
		} );

		if ( selectedModel && ! hasSelectedModel ) {
			var missingOption = document.createElement( 'option' );
			missingOption.value = selectedModel;
			missingOption.textContent = selectedModel + ' (saved)';
			missingOption.selected = true;
			select.appendChild( missingOption );
		}

		status.textContent = models.length === 1 ? '1 model loaded from server.' : models.length + ' models loaded from server.';
		status.style.color = STATUS_COLOR;
	}

	function renderError( message ) {
		var status = document.getElementById( 'lmstudio-model-status' );
		if ( ! status ) {
			return;
		}

		status.textContent = message;
		status.style.color = ERROR_COLOR;
	}

	function loadModels( ajaxUrl, selectedModel ) {
		var status = document.getElementById( 'lmstudio-model-status' );
		if ( ! status ) {
			return;
		}

		status.textContent = 'Loading models...';

		window
			.fetch( ajaxUrl, {
				credentials: 'same-origin',
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Could not connect to load models.' );
				}
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( payload && typeof payload.data === 'string' ? payload.data : 'Failed to load models.' );
				}

				var models = Array.isArray( payload.data ) ? payload.data : [];
				renderModels( models, selectedModel );
			} )
			.catch( function ( error ) {
				renderError( error && error.message ? error.message : 'Failed to load models.' );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! window.aiProviderForLMStudioSettings || ! window.aiProviderForLMStudioSettings.ajaxUrl ) {
			return;
		}

		loadModels(
			window.aiProviderForLMStudioSettings.ajaxUrl,
			window.aiProviderForLMStudioSettings.selectedModel || ''
		);
	} );
}() );
