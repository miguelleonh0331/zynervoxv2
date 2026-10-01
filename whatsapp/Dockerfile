ARG ZYNERWABA_BASE_IMAGE=miguelleonh0331/zynerwabav2:2.0.0@sha256:b6e3ac4ba9115435b151482c6d8c06fbbfe96a572c77cc1e83778c3e68e4c624
FROM ${ZYNERWABA_BASE_IMAGE}

USER root
COPY --chown=node:node overrides/public/app.js /app/public/app.js
COPY --chown=node:node overrides/src/features/empresas/index.js /app/src/features/empresas/index.js
COPY --chown=node:node overrides/views/empresas/index.html /app/views/empresas/index.html
COPY --chown=node:node overrides/views/gestion/index.html /app/views/gestion/index.html
USER node
