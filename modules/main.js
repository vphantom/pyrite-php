'use strict';

var $ = global.jQuery = require('jquery');
var bootstrap = require('bootstrap');  // eslint-disable-line no-unused-vars
var parsley = require('parsleyjs');  // eslint-disable-line no-unused-vars

// Add all useful non-English locales to Parsley
// (This is necessary so that Browserify bundles them all at build time.)
// require('parsleyjs/dist/i18n/fr');

$().ready(function() {
  // Get language code from HTML tag
  // (Default is just in case we miss it.  It _should_ always be set.)
  var lang = $('html').attr('lang') || 'en';

  var excludedInputs = 'input[type=button], input[type=submit], input[type=reset], input[type=hidden], [disabled], :hidden';  // eslint-disable-line max-len

  // Bootstrap-ize forms before enabling Parsley on them
  $('form.form-auto').each(function() {
    $(this)
      .attr('name', $(this).attr('id'))
      .addClass('form-horizontal')
    ;
  });
  $('form.form-auto input')
    .not(excludedInputs)
    .each(function() {
      var id    = $(this).attr('id');
      var label = $(this).attr('data-label');

      $(this)
        .attr('name', id)
        .addClass('form-control')
        .wrap('<div class="form-group"></div>')
        .parent()
        .prepend(
          '<label for="'
          + id
          + '" class="col-sm-2 control-label">'
          + label
          + '</label>'
        )
      ;
      $(this)
        .wrap('<div class="col-sm-10"></div>');
    }
  );
  $('form.form-auto button').each(function() {
    $(this)
      .addClass('btn btn-default')
      .wrap('<div class="form-group"></div>')
      .wrap('<div class="col-sm-offset-2 col-sm-10"></div>')
    ;
  });

  // Set parsley to found language instead of last loaded
  parsley.setLocale(lang);

  // Integrate Parsley with Twitter Bootstrap
  // Initially inspired by https://gist.github.com/askehansen/6809825
  // ...and http://jimmybonney.com/articles/parsley_js_twitter_bootstrap/
  // CAUTION: $.fn.parsley.defaults({...}) was IGNORED.
  $('form').parsley({
    excluded    : excludedInputs,
    successClass: 'has-success',
    errorClass  : 'has-error',
    classHandler: function(el) {
      // This differs from all examples I could find!
      return $(el.$element).closest('.form-group');
    },
    errorsContainer: function() {},
    errorsWrapper  : '<span class="help-block"></span>',
    errorTemplate  : '<span></span>'
  });
});

