import { createFileRoute } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { Msg, Page, useMember } from "@/components/member-kit";
import { memberApi } from "@/lib/member-api";

export const Route = createFileRoute("/member/profile")({
  head: () => ({ meta: [{ title: "My Profile — Life OS Member" }, { name: "description", content: "View and edit your Life OS profile." }] }),
  component: Profile,
});

const FIELDS: [string, string][] = [
  ["name", "Full Name"], ["father_name", "Father's Name"], ["mother_name", "Mother's Name"],
  ["nominee_name", "Nominee's Name"], ["nominee_relation", "Relation With Nominee"], ["dob", "Date of Birth"],
  ["blood_group", "Blood Group"], ["phone_alt", "Alternative Cell Number"],
];

function Profile() {
  const q = useMember<Record<string, string | null>>("profile", "/profile");
  const me = useMember<{ email: string | null; phone: string }>("me", "/me").data;
  const [f, setF] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<{ ok: boolean; t: string } | null>(null);
  useEffect(() => {
    if (q.data) setF(Object.fromEntries(Object.entries(q.data).map(([k, v]) => [k, v ?? ""])));
  }, [q.data]);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await memberApi("/profile", { body: f });
      setMsg({ ok: true, t: "Profile updated successfully." });
      q.refetch();
    } catch (err) {
      setMsg({ ok: false, t: (err as Error).message });
    }
  };

  return (
    <Page title="My Profile">
      <form onSubmit={save}>
        <Msg ok={msg?.ok} text={msg?.t ?? null} />
        <table width="100%" border={0} cellSpacing={0} cellPadding={0}>
          <tbody><tr><td width="60%" valign="top">
            <table width="100%" border={0} cellSpacing={5} cellPadding={0}>
              <tbody>
                {FIELDS.map(([k, l]) => (
                  <tr key={k}>
                    <td width="30%">{l}</td><td width="2%">:</td>
                    <td><input type={k === "dob" ? "date" : "text"} value={f[k] ?? ""} onChange={(e) => setF({ ...f, [k]: e.target.value })} /></td>
                  </tr>
                ))}
                <tr><td>Email Address</td><td>:</td><td><input type="text" readOnly value={me?.email ?? ""} /></td></tr>
                <tr><td>Cell Number</td><td>:</td><td><input type="text" readOnly value={me?.phone ?? ""} /></td></tr>
                <tr><td valign="top">Present Address</td><td valign="top">:</td>
                  <td><textarea rows={4} style={{ width: 300, border: "1px solid #AAA", background: "#FAFAFA" }} value={f.address_current ?? ""} onChange={(e) => setF({ ...f, address_current: e.target.value })} /></td></tr>
                <tr><td /><td /><td><input type="submit" value="Update Profile" /></td></tr>
              </tbody>
            </table>
          </td></tr></tbody>
        </table>
      </form>
    </Page>
  );
}
