# Deployment and Delivery

Deployment is an external side effect and requires explicit user intent.

Before a deployment-related change, identify:
- environment assumptions;
- configuration changes;
- secrets/credentials;
- database compatibility;
- rollback path;
- cache/queue/background worker implications;
- client compatibility;
- observability needed to detect failure.

Prefer reversible rollout mechanisms.

Never deploy, publish, submit a mobile build, push a container, or mutate production infrastructure merely to verify local code.
