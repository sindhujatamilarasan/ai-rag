# ---- Build stage ----
FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# NEXT_PUBLIC_* variables build time-la code-kulla bake aagidum.
# Adhanaala idha ARG-aa inga kudukanum, run time-la kuduthaa velai seyyaadhu.
ARG NEXT_PUBLIC_API_URL=http://localhost:8000/api
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL

RUN npm run build

# ---- Runtime stage ----
FROM node:22-alpine

WORKDIR /app
ENV NODE_ENV=production

# Build stage-la irundhu result mattum edukurom. Source code, dev packages
# ellaam final image-la varaadhu, so image chinnadhaa irukum.
COPY --from=build /app/package.json /app/package-lock.json ./
RUN npm ci --omit=dev
COPY --from=build /app/.next ./.next
COPY --from=build /app/public ./public

EXPOSE 3000

CMD ["npm", "start"]