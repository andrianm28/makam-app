# Design — Package and Service Bundles

Entities: `service_packages`, `service_package_versions`, `service_package_items`, `service_definitions`, `price_versions`, `substitution_policies`, `evidence_requirements`.

A package expander creates quote lines and fulfillment intents. Later changes create new versions, never mutate accepted quote contents.
