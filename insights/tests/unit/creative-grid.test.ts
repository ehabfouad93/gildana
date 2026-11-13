import { describe, expect, it } from "vitest";
import { permutations, variantName, variantStory } from "@/platforms/meta/creative-grid";

describe("creative grid", () => {
  it("makes every combination, keeping the source's element where none is given", () => {
    expect(permutations(["A", "B"], ["t1", "t2", "t3"], [], ["LEARN_MORE"])).toHaveLength(6);
    expect(permutations(["A"], [], [], [])).toEqual([{ headline: "A", text: undefined, imageHash: undefined, cta: undefined }]);
    expect(permutations([], [], [], [])).toHaveLength(1);
  });

  it("swaps only the chosen elements of an image ad", () => {
    const story = { page_id: "1", link_data: { name: "Old", message: "Old text", link: "https://x.test", picture: "p.png", call_to_action: { type: "LEARN_MORE", value: { lead_gen_form_id: "f1" } } } };
    const s = variantStory(story, { headline: "New", imageHash: "abc", cta: "SIGN_UP" })!;
    expect(s.link_data).toMatchObject({ name: "New", message: "Old text", image_hash: "abc", link: "https://x.test" });
    expect(s.link_data.picture).toBeUndefined();
    // The button's destination (lead form) is kept; only its label changes.
    expect(s.link_data.call_to_action).toEqual({ type: "SIGN_UP", value: { lead_gen_form_id: "f1" } });
    expect(story.link_data.name).toBe("Old");
  });

  it("handles video ads and refuses formats it can't vary", () => {
    expect(variantStory({ video_data: { title: "Old", message: "m", video_id: "v" } }, { headline: "New", text: "t" })!.video_data).toMatchObject({ title: "New", message: "t", video_id: "v" });
    expect(variantStory({ link_data: { child_attachments: [{}] } }, { headline: "x" })).toBeNull();
    expect(variantStory(null, { headline: "x" })).toBeNull();
  });

  it("names each variant after what it changes", () => {
    expect(variantName("Ad", { headline: "Own your home", cta: "SIGN_UP" }, 2)).toBe("Ad · v2 · H: Own your home · sign up");
  });
});
