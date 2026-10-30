import { PrismaClient } from "@prisma/client";

// One client per process; in dev, hot reload would otherwise open a new pool
// on every edit.
const g = globalThis as unknown as { prisma?: PrismaClient };

export const db = g.prisma ?? new PrismaClient();

if (process.env.NODE_ENV !== "production") g.prisma = db;
