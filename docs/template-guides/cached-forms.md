# Cached Forms
When using caching mechanisms with Formie, it's worth taking note of some caveats to ensure things work correctly.

## Template Caching
If you are using the `{% cache %}` Twig functions in your templates, you'll need to be mindful of CSS and JS assets will not work. For example, say you have a Twig template with the following:

```twig
<html>
<head></head>
<body>
    {% cache %}

    {{ craft.formie.renderForm('contactForm') }}

    {% endcache %}
</body>
</html>
```

Here, we're using the `{% cache %}` tag to wrap out `renderForm` function. What this will do is cache the HTML generated by the function. Whilst this is benefitial, the `renderForm` function also registers the CSS and JS that Formie uses, and this won't be cached. You'll find on subsequent page-reloads that the CSS and JS will not render.

To get around this, you'll need to call `craft.formie.registerAssets()` outside of your cached content. This will tell Formie and Craft to render the CSS and JS for the form.

 ```twig
<html>
<head></head>
<body>
    {% cache %}

    {{ craft.formie.renderForm('contactForm') }}

    {% endcache %}

    {# Register the assets used by Formie, outside of the cached tags #}
    {% do craft.formie.registerAssets('contactForm') %}
</body>
</html>
```
