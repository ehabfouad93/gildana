import { publicUrl } from "@/lib/app-url";
import { openApi } from "@/modules/api/openapi";

export function GET(req: Request) {
  return Response.json(openApi(publicUrl("/", req).origin));
}
