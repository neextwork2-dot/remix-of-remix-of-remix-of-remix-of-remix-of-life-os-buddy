import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { Msg, Page } from "@/components/member-kit";
import { memberApi } from "@/lib/member-api";

export const Route = createFileRoute("/member/change-password")({
  head: () => ({ meta: [{ title: "Change Password — Life OS Member" }, { name: "description", content: "Change your Life OS password." }] }),
  component: ChangePassword,
});

function ChangePassword() {
  const [cur, setCur] = useState("");
  const [nw, setNw] = useState("");
  const [cf, setCf] = useState("");
  const [msg, setMsg] = useState<{ ok: boolean; t: string } | null>(null);
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (nw !== cf) return setMsg({ ok: false, t: "New password and confirm password do not match." });
    try {
      await memberApi("/password", { body: { current_password: cur, new_password: nw } });
      setMsg({ ok: true, t: "Password changed successfully." });
      setCur(""); setNw(""); setCf("");
    } catch (err) {
      setMsg({ ok: false, t: (err as Error).message });
    }
  };
  return (
    <Page title="Change Password">
      <form onSubmit={submit}>
        <Msg ok={msg?.ok} text={msg?.t ?? null} />
        <table border={0} cellSpacing={5} cellPadding={0}>
          <tbody>
            <tr><td width={180}>Current Password</td><td>:</td><td><input type="password" value={cur} onChange={(e) => setCur(e.target.value)} required /></td></tr>
            <tr><td>New Password</td><td>:</td><td><input type="password" value={nw} onChange={(e) => setNw(e.target.value)} required minLength={6} /></td></tr>
            <tr><td>Confirm Password</td><td>:</td><td><input type="password" value={cf} onChange={(e) => setCf(e.target.value)} required /></td></tr>
            <tr><td /><td /><td><input type="submit" value="Change Password" /></td></tr>
          </tbody>
        </table>
      </form>
    </Page>
  );
}
