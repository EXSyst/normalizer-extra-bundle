# What is that?
A mostly opinionated bundle which aims to ease creation of efficient API servers with the Symfony serializer, while trying to stay lightweight.

# How to use?
1. Make sure you have a Symfony 3.* or 4.* project, with the Serializer component, and the Doctrine ORM ;
2. Run `composer require exsyst/normalizer-extra-bundle` in your project ;
3. Add a section to your project configuration to choose the features you want to use (see below) ;
4. Further customize and/or extend the bundle's behavior if you want (documentation will come later) ;
5. Start (de)serializing things!

# Features and default configuration
The only features that are enabled by default are the ones that :
- Are inert on their own, or ;
- Define a reasonable, unopinionated behavior for cases that were completely unsupported.
```yaml
exsyst_normalizer_extra:
  features:
    # Makes $request->request able to access the request body in the
    # Content-Type: application/json, application/xml, application/x-yaml,
    # text/csv cases and/or others depending on serializer support.
    # Enabled by default.
    request_decoder: true

    # Parses a JSON document in a header named "Response-Shape",
    # into a request attribute named "shape", used so that the client
    # can filter out unneeded fields.
    # Enabled by default.
    response_shape_header: true

    # Allows a controller to return an object or null and have it
    # automatically turned into a Response.
    # Disabled by default.
    serializer_view_listener: false

    # Allows a controller to throw a HttpException and have it
    # automatically turned into a Response.
    # Disabled by default.
    serializer_exception_listener: false

  normalizers:
    # A normalizer geared towards Doctrine collections, which also
    # supports most iterables (though in a limited way).
    # Disabled by default.
    collection: false

    # A meta-normalizer that can generate fast normalizers for most
    # classes, and delegate to them.
    # Disabled by default.
    specializing: false
  options:
    # Always use a breadth-first normalization algorithm, that can
    # optimize initialization operations by batching them.
    # Some normalizers may be incompatible.
    # Disabled by default.
    implicit_breadth_first: false

    # Automatically provides metadata consumers with information
    # obtained by using Symfony's PropertyInfo component and serializer
    # metadata, Doctrine's mappings, and annotations.
    # Enabled by default.
    auto_metadata: true

    # Parameters to use in the (de)serialization context of the services
    # defined by enabling the above features.
    # Null by default, which is treated the same as an empty mapping.
    default_context: ~
  unsafe_features:
    # Optimizes Doctrine collection initializations by batching them.
    # Disabled by default.
    collection_batching: false

    # Optimizes Doctrine entity proxy initializations by batching them.
    # Disabled by default.
    entity_batching: false
```