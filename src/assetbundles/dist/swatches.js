(function ($) {
    // Hyper can replace field markup before its queued initialization scripts run, so all handlers are delegated.
    var clickSelector = '.wd-colour-swatches [data-swatch], .wd-colour-swatches [data-clear]';
    var changeSelector = '.wd-colour-swatches [data-shade], .wd-colour-swatches [data-opacity]';

    function parse(json, fallback) {
        try {
            return JSON.parse(json) || fallback;
        } catch (e) {
            return fallback;
        }
    }

    function selectedSwatch($container) {
        return $container.find('[data-swatch][aria-pressed="true"]').first();
    }

    // Saved values outside the allowed list are rendered as extra options; drop them once they are no longer selected.
    function removeUnavailable($select, type) {
        $select.find('option[data-unavailable-option]').filter(function () {
            return !this.selected || this.disabled;
        }).each(function () {
            if (this.hasAttribute('data-extra-option')) {
                $(this).remove();
            } else {
                this.removeAttribute('data-unavailable-option');
            }
        });
        if (!$select.find('option[data-unavailable-option]:selected').length) {
            $select.closest('.wd-colour-swatches').find('[data-unavailable-modifier="' + type + '"]').remove();
        }
    }

    // Enables the shades the selected swatch supports and resets an unsupported shade to the base colour.
    function syncShades($container) {
        var $select = $container.find('[data-shade]');
        if (!$select.length) {
            return;
        }
        var $swatch = selectedSwatch($container);
        var shades = $swatch.length ? parse($swatch.attr('data-shades'), {}) : {};
        $select.find('option').each(function () {
            if (this.value !== '') {
                this.disabled = !Object.prototype.hasOwnProperty.call(shades, this.value);
            }
        });
        if ($select.find('option:selected').prop('disabled')) {
            $select.val('');
        }
        $select.find('option[data-unavailable-option]').filter(function () {
            return !this.disabled;
        }).removeAttr('data-unavailable-option');
        removeUnavailable($select, 'shade');
    }

    function updatePreview($container) {
        var $swatch = selectedSwatch($container);
        var shade = $container.find('[data-shade]').val() || '';
        var opacity = $container.find('[data-opacity]').val() || '';
        var color = 'transparent';
        if ($swatch.length) {
            var shades = parse($swatch.attr('data-shades'), {});
            color = shade !== '' && Object.prototype.hasOwnProperty.call(shades, shade) ? shades[shade] : $swatch.attr('data-color');
        }
        $container.find('[data-preview] > span').css({
            background: color,
            opacity: opacity === '' ? 1 : parseInt(opacity, 10) / 100
        });
    }

    function write($container) {
        var $swatch = selectedSwatch($container);
        var value = '';
        if ($swatch.length) {
            var swatch = parse($swatch.attr('data-swatch'), {});
            var shade = $container.find('[data-shade]').val() || '';
            var opacity = $container.find('[data-opacity]').val() || '';
            swatch.shade = shade === '' ? null : shade;
            swatch.opacity = opacity === '' ? null : parseInt(opacity, 10);
            value = JSON.stringify(swatch);
        }
        updatePreview($container);
        $container.find('input[type="hidden"]').val(value).trigger('input').trigger('change');
    }

    $(document).off('click.multisiteColourSwatches', clickSelector).on('click.multisiteColourSwatches', clickSelector, function () {
        var $option = $(this);
        if ($option.prop('disabled')) {
            return;
        }
        var $container = $option.closest('.wd-colour-swatches');
        var clear = $option.is('[data-clear]') || $option.attr('aria-pressed') === 'true';
        var $modifiers = $container.find('[data-shade], [data-opacity]');
        $container.find('[data-swatch]').attr('aria-pressed', 'false');
        $container.find('[data-unavailable]').remove();
        if (clear) {
            $modifiers.val('').prop('disabled', true);
            $modifiers.find('option[data-extra-option]').remove();
            $modifiers.find('option[data-unavailable-option]').removeAttr('data-unavailable-option');
            $container.find('[data-unavailable-modifier]').remove();
        } else {
            $option.attr('aria-pressed', 'true');
            $modifiers.prop('disabled', false);
            syncShades($container);
        }
        write($container);
    });

    $(document).off('change.multisiteColourSwatches', changeSelector).on('change.multisiteColourSwatches', changeSelector, function () {
        var $select = $(this);
        var $container = $select.closest('.wd-colour-swatches');
        if ($select.prop('disabled') || !selectedSwatch($container).length) {
            return;
        }
        removeUnavailable($select, $select.is('[data-shade]') ? 'shade' : 'opacity');
        write($container);
    });
})(jQuery);
