// User guide shown from the ⓘ button after each page title (mw-guide). Written for
// what each page does today; the mockup's fuller guides arrive with their modules.

import type { Locale } from "@/i18n";
import type { PageId } from "./nav";

export type Guide = { intro: string; steps: string[]; tips: string[] };

type Bilingual = Record<Locale, Guide>;

const GUIDES: Partial<Record<PageId, Bilingual>> = {
  dashboard: {
    en: {
      intro: "Your starting point. It will show your tasks, check-ins due, approvals, KPI and meetings as each module goes live.",
      steps: ["Use the menu on the left to open any page you have access to.", "Click your name at the bottom of the menu to open My Profile."],
      tips: ["The menu only shows pages your access role allows.", "Collapse the menu to an icon rail with the button above your name."],
    },
    "zh-CN": {
      intro: "这是您的起始页。各模块上线后，会显示您的任务、待汇报进度、待审批事项、绩效指标和会议。",
      steps: ["使用左侧菜单打开您有权访问的页面。", "点击菜单底部的姓名打开“我的资料”。"],
      tips: ["菜单只显示您的访问角色允许的页面。", "点击姓名上方的按钮可将菜单收起为图标栏。"],
    },
  },
  profile: {
    en: {
      intro: "Your own account: display name, picture, password, two-factor authentication and language.",
      steps: [
        "Overview: change your display name or upload a picture (only you see it).",
        "Security: change your password and add up to two authenticator devices.",
        "Preferences: choose English or 中文. The choice is saved to your account.",
      ],
      tips: ["Keep at least one 2FA device if your organisation requires it.", "Save your recovery codes somewhere safe: each works once if you lose your phone."],
    },
    "zh-CN": {
      intro: "您自己的账号：显示名称、头像、密码、双重验证和语言。",
      steps: ["概览：修改显示名称或上传头像（仅您本人可见）。", "安全：修改密码，最多添加两台验证器设备。", "偏好设置：选择 English 或中文，选择会保存到您的账号。"],
      tips: ["如组织要求双重验证，请至少保留一台设备。", "请妥善保存恢复码：手机丢失时，每个恢复码可使用一次。"],
    },
  },
  access: {
    en: {
      intro: "What each access role is allowed to do. Changes apply at once to everyone with that role.",
      steps: ["Switch a permission on or off in a role's column.", "The System Admin role always has everything and can't be edited."],
      tips: ["You can't remove Global Settings access from your own role.", "The menu follows these permissions, so people see the change the next time a page loads."],
    },
    "zh-CN": {
      intro: "每个访问角色可以做什么。修改会立即作用于该角色的所有用户。",
      steps: ["在角色所在列开启或关闭某项权限。", "系统管理员角色始终拥有全部权限，不可编辑。"],
      tips: ["您不能移除自己角色的“全局设置”权限。", "菜单按这些权限显示，用户下次加载页面时即可看到变化。"],
    },
  },
  config: {
    en: {
      intro: "Company-wide settings: company details, security, work rules, employment defaults, employee numbers, recruitment and KPI.",
      steps: ["Change any values, then click Save settings. Only the values you changed are sent.", "Turn on two-factor authentication and choose which roles must use it."],
      tips: ["Session timeout signs people out after inactivity.", "If someone else saved in the meantime, you'll see their values and can make your change again."],
    },
    "zh-CN": {
      intro: "全公司范围的设置：公司信息、安全、工作规则、雇佣默认值、员工编号、招聘和绩效。",
      steps: ["修改所需的值后点击“保存设置”，只会提交您修改过的内容。", "开启双重验证并选择必须使用的角色。"],
      tips: ["会话超时会在闲置后自动退出登录。", "如果期间有其他人保存过，页面会显示最新值，您可以重新修改。"],
    },
  },
  updates: {
    en: {
      intro: "The version installed on this server.",
      steps: ["Check the version and build when you report a problem."],
      tips: ["Automatic updates and the update log arrive with the deploy script (M8)."],
    },
    "zh-CN": {
      intro: "此服务器上安装的版本。",
      steps: ["报告问题时请附上版本号和构建信息。"],
      tips: ["自动更新和更新日志将随部署脚本（M8）上线。"],
    },
  },
};

const COMING: Bilingual = {
  en: {
    intro: "This page isn't built yet. It arrives with its module; the menu shows it now so you know where it will live.",
    steps: ["Go back to the dashboard, or pick another page from the menu."],
    tips: [],
  },
  "zh-CN": {
    intro: "此页面尚未建成，将随对应模块上线。菜单已预先显示，方便您了解它的位置。",
    steps: ["返回仪表板，或从菜单中选择其他页面。"],
    tips: [],
  },
};

export function guideFor(page: PageId, locale: Locale): Guide {
  return (GUIDES[page] ?? COMING)[locale];
}
