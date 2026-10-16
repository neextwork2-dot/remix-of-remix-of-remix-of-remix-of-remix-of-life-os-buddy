import { createFileRoute } from "@tanstack/react-router";
import { Page } from "@/components/member-kit";

export const Route = createFileRoute("/member/notice")({
  head: () => ({ meta: [{ title: "Account Notice — Life OS Member" }, { name: "description", content: "Life OS member notices." }] }),
  component: () => (
    <Page title="Account Notice">
      <div className="notice_cotainer">
        <div className="notice_div">
          <h4><u>Welcome to Life OS</u></h4>
          <p>Complete your profile and nominee information. Cashback balance can only be used for Life OS services; it cannot be withdrawn or transferred.</p>
        </div>
      </div>
    </Page>
  ),
});
